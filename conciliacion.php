<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] != 'admin' && $_SESSION['rol'] != 'consolidador')) {
    header('Location: login.php');
    exit();
}

$usuario_id = $_SESSION['usuario_id'];
$db = new Database('A');
$conn = $db->getConnection();

// Procesar archivo del banco
$movimientos_banco = [];
$conciliaciones_automaticas = [];
$conciliaciones_pendientes = [];
$archivo_procesado = false;

if (isset($_POST['procesar_conciliacion'])) {
    try {
        // 1. Cargar movimientos bancarios desde Excel
        if (isset($_FILES['archivo_banco']) && $_FILES['archivo_banco']['error'] == 0) {
            $banco_seleccionado = $_POST['banco'];
            require_once 'libs/PHPExcel/Classes/PHPExcel.php';
            
            $archivo_tmp = $_FILES['archivo_banco']['tmp_name'];
            $objPHPExcel = PHPExcel_IOFactory::load($archivo_tmp);
            $sheet = $objPHPExcel->getActiveSheet();
            $highestRow = $sheet->getHighestRow();
            
            // Determinar formato según el banco seleccionado
            switch($banco_seleccionado) {
                case 'provincial':
                    // Formato Provincial: Fecha | Descripción | Monto | Saldo
                    for ($row = 2; $row <= $highestRow; $row++) {
                        $fecha_celda = $sheet->getCell('A' . $row)->getValue();
                        $descripcion = trim($sheet->getCell('B' . $row)->getValue());
                        $monto_str = $sheet->getCell('C' . $row)->getValue();
                        // $saldo = $sheet->getCell('D' . $row)->getValue(); // No necesario para conciliación
                        
                        if (!empty($descripcion) && !empty($monto_str)) {
                            // Convertir fecha
                            $fecha = '';
                            if (PHPExcel_Shared_Date::isDateTime($sheet->getCell('A' . $row))) {
                                $fecha_timestamp = PHPExcel_Shared_Date::ExcelToPHP($fecha_celda);
                                $fecha = date('Y-m-d', $fecha_timestamp);
                            } else {
                                // Intentar parsear fecha en formato dd/mm/yyyy
                                $fecha = DateTime::createFromFormat('d/m/Y', $fecha_celda);
                                if ($fecha) {
                                    $fecha = $fecha->format('Y-m-d');
                                } else {
                                    $fecha = date('Y-m-d'); // Fecha actual por defecto
                                }
                            }
                            
                            // Convertir monto (formato 302,20 a 302.20)
                            $monto = floatval(str_replace(',', '.', str_replace('.', '', $monto_str)));
                            
                            // Extraer referencia de la descripción
                            $referencia = $this->extraerReferenciaProvincial($descripcion);
                            
                            if ($monto > 0) { // Solo ingresos (pagos recibidos)
                                $movimientos_banco[] = [
                                    'referencia' => $referencia,
                                    'descripcion' => $descripcion,
                                    'monto' => $monto,
                                    'fecha' => $fecha,
                                    'banco' => 'Provincial'
                                ];
                            }
                        }
                    }
                    break;
                    
                case 'venezuela':
                    // Formato Banco de Venezuela (ajustar según el formato real)
                    for ($row = 2; $row <= $highestRow; $row++) {
                        $fecha_celda = $sheet->getCell('A' . $row)->getValue();
                        $descripcion = trim($sheet->getCell('B' . $row)->getValue());
                        $monto_str = $sheet->getCell('C' . $row)->getValue();
                        
                        if (!empty($descripcion) && !empty($monto_str)) {
                            // Procesamiento similar al Provincial, ajustar según formato real
                            $fecha = $this->procesarFechaExcel($fecha_celda);
                            $monto = floatval(str_replace(',', '.', str_replace('.', '', $monto_str)));
                            $referencia = $this->extraerReferenciaVenezuela($descripcion);
                            
                            if ($monto > 0) {
                                $movimientos_banco[] = [
                                    'referencia' => $referencia,
                                    'descripcion' => $descripcion,
                                    'monto' => $monto,
                                    'fecha' => $fecha,
                                    'banco' => 'Venezuela'
                                ];
                            }
                        }
                    }
                    break;
                    
                default:
                    // Formato genérico
                    for ($row = 2; $row <= $highestRow; $row++) {
                        $referencia = trim($sheet->getCell('A' . $row)->getValue());
                        $descripcion = trim($sheet->getCell('B' . $row)->getValue());
                        $monto = floatval($sheet->getCell('C' . $row)->getValue());
                        $fecha_celda = $sheet->getCell('D' . $row)->getValue();
                        
                        if (!empty($referencia) && $monto > 0) {
                            $fecha = $this->procesarFechaExcel($fecha_celda);
                            
                            $movimientos_banco[] = [
                                'referencia' => $referencia,
                                'descripcion' => $descripcion,
                                'monto' => $monto,
                                'fecha' => $fecha,
                                'banco' => $banco_seleccionado
                            ];
                        }
                    }
            }
            
            $archivo_procesado = true;
            $_SESSION['movimientos_banco'] = $movimientos_banco;
        }
        
        // 2. Obtener movimientos contables del sistema (pagos pendientes)
        $sql_movimientos_sistema = "SELECT 
            p.id,
            p.referencia,
            p.descripcion,
            p.monto,
            p.moneda,
            p.monto_bs as monto_contable,
            p.fecha_pago,
            p.estado,
            b.nombre as banco_nombre
            FROM pagos p
            LEFT JOIN bancos b ON p.banco_id = b.id
            WHERE p.estado IN ('pendiente', 'aprobado')
            ORDER BY p.fecha_pago DESC";
        
        $stmt_sistema = sqlsrv_query($conn, $sql_movimientos_sistema);
        $movimientos_sistema = [];
        while ($row = sqlsrv_fetch_array($stmt_sistema, SQLSRV_FETCH_ASSOC)) {
            $movimientos_sistema[] = $row;
        }
        
        // 3. Conciliación automática
        foreach ($movimientos_sistema as $sistema) {
            foreach ($movimientos_banco as $key => $banco) {
                // Para Provincial, la referencia está embebida en la descripción
                $coincide_referencia = $this->compararReferencias($sistema['referencia'], $banco['referencia']);
                
                // Calcular monto del sistema en Bs
                $monto_sistema = $sistema['moneda'] == 'USD' ? $sistema['monto_contable'] : $sistema['monto'];
                $diferencia_monto = abs($monto_sistema - $banco['monto']);
                $coincide_monto = $diferencia_monto < 1.00; // Tolerancia de 1.00 Bs por diferencias de decimales
                
                if ($coincide_referencia && $coincide_monto) {
                    $conciliaciones_automaticas[] = [
                        'sistema' => $sistema,
                        'banco' => $banco,
                        'tipo' => 'automática',
                        'diferencia_monto' => $diferencia_monto
                    ];
                    
                    unset($movimientos_banco[$key]);
                    break;
                }
            }
        }
        
        // 4. Conciliaciones pendientes (sin coincidencia automática)
        foreach ($movimientos_sistema as $sistema) {
            $encontrado = false;
            foreach ($conciliaciones_automaticas as $conciliacion) {
                if ($conciliacion['sistema']['id'] == $sistema['id']) {
                    $encontrado = true;
                    break;
                }
            }
            
            if (!$encontrado) {
                $conciliaciones_pendientes[] = [
                    'sistema' => $sistema,
                    'banco' => null,
                    'tipo' => 'pendiente'
                ];
            }
        }
        
        // Movimientos bancarios sin conciliar
        foreach ($movimientos_banco as $banco) {
            $conciliaciones_pendientes[] = [
                'sistema' => null,
                'banco' => $banco,
                'tipo' => 'banco_sin_match'
            ];
        }
        
    } catch (Exception $e) {
        $error = "Error en el proceso de conciliación: " . $e->getMessage();
    }
}

// Función para extraer referencia del formato Provincial
function extraerReferenciaProvincial($descripcion) {
    // Ejemplo: "ABO.DRV0021061576" -> extraer "0021061576"
    if (preg_match('/DRV(\d+)/', $descripcion, $matches)) {
        return $matches[1];
    }
    
    // Si no encuentra patrón DRV, buscar números de 8-10 dígitos
    if (preg_match('/(\d{8,10})/', $descripcion, $matches)) {
        return $matches[1];
    }
    
    // Si no encuentra números, usar la descripción completa
    return $descripcion;
}

// Función para extraer referencia del formato Venezuela
function extraerReferenciaVenezuela($descripcion) {
    // Ajustar según el formato real del Banco de Venezuela
    if (preg_match('/(\d{8,12})/', $descripcion, $matches)) {
        return $matches[1];
    }
    
    return $descripcion;
}

// Función para comparar referencias (flexible)
function compararReferencias($ref_sistema, $ref_banco) {
    // Limpiar referencias
    $ref_sistema = preg_replace('/[^0-9]/', '', $ref_sistema);
    $ref_banco = preg_replace('/[^0-9]/', '', $ref_banco);
    
    // Coincidencia exacta
    if ($ref_sistema === $ref_banco) {
        return true;
    }
    
    // Coincidencia parcial (últimos 6-8 dígitos)
    if (strlen($ref_sistema) >= 6 && strlen($ref_banco) >= 6) {
        $ultimos_sistema = substr($ref_sistema, -6);
        $ultimos_banco = substr($ref_banco, -6);
        
        if ($ultimos_sistema === $ultimos_banco) {
            return true;
        }
    }
    
    return false;
}

// Función para procesar fechas de Excel
function procesarFechaExcel($fecha_celda) {
    if (PHPExcel_Shared_Date::isDateTime($fecha_celda)) {
        $fecha_timestamp = PHPExcel_Shared_Date::ExcelToPHP($fecha_celda);
        return date('Y-m-d', $fecha_timestamp);
    } else {
        // Intentar parsear fecha en formato dd/mm/yyyy
        $fecha = DateTime::createFromFormat('d/m/Y', $fecha_celda);
        if ($fecha) {
            return $fecha->format('Y-m-d');
        }
    }
    
    return date('Y-m-d'); // Fecha actual por defecto
}

// Ejecutar conciliación confirmada
if (isset($_POST['confirmar_conciliacion'])) {
    try {
        $conciliaciones = json_decode($_POST['conciliaciones_data'], true);
        $contador = 0;
        
        foreach ($conciliaciones as $conciliacion) {
            if ($conciliacion['conciliado'] && $conciliacion['id_sistema']) {
                // Marcar como conciliado en sistema
                $sql_update = "UPDATE pagos SET estado = 'conciliado', fecha_conciliacion = GETDATE() WHERE id = ?";
                sqlsrv_query($conn, $sql_update, array($conciliacion['id_sistema']));
                
                $contador++;
            }
        }
        
        $_SESSION['success'] = "Conciliación completada. $contador registros conciliados.";
        header('Location: conciliacion.php');
        exit();
        
    } catch (Exception $e) {
        $error = "Error al confirmar conciliación: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conciliación Bancaria Automática</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <style>
        .proceso-conciliacion {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .formatos-banco {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 15px 0;
        }
        .formato-banco {
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        .formato-banco:hover {
            border-color: #007bff;
            background: #f8f9fa;
        }
        .formato-banco.selected {
            border-color: #28a745;
            background: #d4edda;
        }
        .formato-banco input[type="radio"] {
            display: none;
        }
        .banco-icon {
            font-size: 24px;
            margin-bottom: 10px;
            color: #6c757d;
        }
        .formato-banco.selected .banco-icon {
            color: #28a745;
        }
        .resumen-conciliacion {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin: 20px 0;
        }
        .resumen-item {
            text-align: center;
            padding: 15px;
            border-radius: 5px;
            color: white;
            font-weight: bold;
        }
        .resumen-automatico { background: #28a745; }
        .resumen-pendiente { background: #ffc107; color: black; }
        .resumen-sin-match { background: #dc3545; }
        .conciliacion-item {
            padding: 10px 15px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .conciliacion-item.automatica {
            background: #d4edda;
            border-left: 4px solid #28a745;
        }
        .item-details {
            flex: 1;
        }
        .referencia-match {
            background: #28a745;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 12px;
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <!-- ... mismo sidebar ... -->
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="top-nav">
                <h3>Conciliación Bancaria Automática</h3>
                <div class="user-info">
                    <span>Bienvenido, <?php echo $_SESSION['nombre']; ?></span>
                    <span class="badge"><?php echo ucfirst($_SESSION['rol']); ?></span>
                </div>
            </div>

            <div class="content">
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                <?php endif; ?>

                <!-- Paso 1: Seleccionar banco y cargar archivo -->
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-file-upload"></i> Paso 1: Seleccionar Banco y Cargar Archivo</h4>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data" class="proceso-conciliacion">
                            <div class="form-group">
                                <label>Seleccionar Banco:</label>
                                <div class="formatos-banco">
                                    <label class="formato-banco" onclick="selectBanco(this)">
                                        <input type="radio" name="banco" value="provincial" required>
                                        <div class="banco-icon">
                                            <i class="fas fa-university"></i>
                                        </div>
                                        <strong>Provincial</strong>
                                        <small>Formato: Fecha | Descripción | Monto | Saldo</small>
                                    </label>
                                    
                                    <label class="formato-banco" onclick="selectBanco(this)">
                                        <input type="radio" name="banco" value="venezuela">
                                        <div class="banco-icon">
                                            <i class="fas fa-university"></i>
                                        </div>
                                        <strong>Venezuela</strong>
                                        <small>Formato específico BDV</small>
                                    </label>
                                    
                                    <label class="formato-banco" onclick="selectBanco(this)">
                                        <input type="radio" name="banco" value="banesco">
                                        <div class="banco-icon">
                                            <i class="fas fa-university"></i>
                                        </div>
                                        <strong>Banesco</strong>
                                        <small>Formato genérico</small>
                                    </label>
                                    
                                    <label class="formato-banco" onclick="selectBanco(this)">
                                        <input type="radio" name="banco" value="mercantil">
                                        <div class="banco-icon">
                                            <i class="fas fa-university"></i>
                                        </div>
                                        <strong>Mercantil</strong>
                                        <small>Formato genérico</small>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="archivo_banco">Seleccionar archivo del banco:</label>
                                <input type="file" name="archivo_banco" accept=".xlsx,.xls,.csv" required class="form-control">
                                <small class="form-text text-muted">
                                    <strong>Formatos soportados:</strong> Excel (.xlsx, .xls) o CSV<br>
                                    <strong>Provincial:</strong> Las referencias se extraen automáticamente de la descripción
                                </small>
                            </div>
                            
                            <button type="submit" name="procesar_conciliacion" class="btn btn-primary btn-lg">
                                <i class="fas fa-cogs"></i> Procesar Conciliación Automática
                            </button>
                        </form>
                    </div>
                </div>

                <?php if ($archivo_procesado): ?>
                
                <!-- Resumen de Resultados -->
                <div class="resumen-conciliacion">
                    <div class="resumen-item resumen-automatico">
                        <i class="fas fa-check-circle fa-2x"></i><br>
                        Conciliados Automáticamente<br>
                        <span style="font-size: 24px;"><?php echo count($conciliaciones_automaticas); ?></span>
                    </div>
                    <div class="resumen-item resumen-pendiente">
                        <i class="fas fa-clock fa-2x"></i><br>
                        Pendientes de Revisión<br>
                        <span style="font-size: 24px;"><?php echo count($conciliaciones_pendientes); ?></span>
                    </div>
                    <div class="resumen-item resumen-sin-match">
                        <i class="fas fa-exclamation-triangle fa-2x"></i><br>
                        Movimientos Bancarios<br>
                        <span style="font-size: 24px;"><?php echo count($movimientos_banco); ?></span>
                    </div>
                </div>

                <!-- Paso 2: Resultados de Conciliación -->
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-list-alt"></i> Paso 2: Resultados de Conciliación Automática</h4>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="formConfirmarConciliacion">
                            <input type="hidden" name="conciliaciones_data" id="conciliacionesData">
                            
                            <!-- Conciliaciones Automáticas -->
                            <?php if (count($conciliaciones_automaticas) > 0): ?>
                            <h5 style="color: #28a745; margin-bottom: 15px;">
                                <i class="fas fa-check-circle"></i> 
                                <?php echo count($conciliaciones_automaticas); ?> Conciliaciones Automáticas Detectadas
                            </h5>
                            
                            <?php foreach ($conciliaciones_automaticas as $index => $conciliacion): 
                                $fecha_sistema = $conciliacion['sistema']['fecha_pago'] instanceof DateTime ? 
                                    $conciliacion['sistema']['fecha_pago']->format('d/m/Y') : 
                                    date('d/m/Y', strtotime($conciliacion['sistema']['fecha_pago']));
                            ?>
                            <div class="conciliacion-item automatica">
                                <div class="item-details">
                                    <strong>
                                        Referencia: <?php echo $conciliacion['sistema']['referencia']; ?>
                                        <span class="referencia-match">✓ COINCIDE</span>
                                    </strong><br>
                                    <small>
                                        <strong>Sistema:</strong> 
                                        <?php echo $conciliacion['sistema']['moneda'] == 'USD' ? '$' : 'Bs '; ?>
                                        <?php echo number_format($conciliacion['sistema']['monto'], 2); ?> 
                                        (<?php echo $conciliacion['sistema']['moneda']; ?>) |
                                        Fecha: <?php echo $fecha_sistema; ?><br>
                                        
                                        <strong>Banco:</strong> 
                                        Bs <?php echo number_format($conciliacion['banco']['monto'], 2); ?> |
                                        Fecha: <?php echo date('d/m/Y', strtotime($conciliacion['banco']['fecha'])); ?> |
                                        Descripción: <?php echo $conciliacion['banco']['descripcion']; ?>
                                    </small>
                                </div>
                                <div class="item-actions">
                                    <input type="checkbox" 
                                           name="conciliacion_<?php echo $index; ?>" 
                                           value="1" 
                                           checked 
                                           onchange="actualizarDatosConciliacion()"
                                           data-sistema-id="<?php echo $conciliacion['sistema']['id']; ?>"
                                           data-referencia="<?php echo $conciliacion['sistema']['referencia']; ?>">
                                    <label>Conciliar</label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>

                            <!-- Botón de Confirmación -->
                            <?php if (count($conciliaciones_automaticas) > 0): ?>
                            <div class="action-buttons" style="text-align: center; margin-top: 30px;">
                                <button type="submit" name="confirmar_conciliacion" class="btn btn-success btn-lg">
                                    <i class="fas fa-check-double"></i> Confirmar <?php echo count($conciliaciones_automaticas); ?> Conciliaciones
                                </button>
                                <small class="form-text text-muted">
                                    Los pagos se marcarán como "CONCILIADOS" y no podrán ser modificados
                                </small>
                            </div>
                            <?php else: ?>
                            <div class="alert alert-warning text-center">
                                <i class="fas fa-info-circle"></i>
                                No se encontraron conciliaciones automáticas. Revise manualmente los registros.
                            </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function selectBanco(element) {
            // Deseleccionar todos
            document.querySelectorAll('.formato-banco').forEach(el => {
                el.classList.remove('selected');
            });
            
            // Seleccionar actual
            element.classList.add('selected');
            element.querySelector('input[type="radio"]').checked = true;
        }
        
        function actualizarDatosConciliacion() {
            const conciliaciones = [];
            const checkboxes = document.querySelectorAll('input[type="checkbox"][name^="conciliacion_"]');
            
            checkboxes.forEach((checkbox, index) => {
                if (checkbox.checked) {
                    conciliaciones.push({
                        id_sistema: checkbox.getAttribute('data-sistema-id'),
                        referencia: checkbox.getAttribute('data-referencia'),
                        conciliado: true
                    });
                }
            });
            
            document.getElementById('conciliacionesData').value = JSON.stringify(conciliaciones);
        }
        
        // Inicializar datos al cargar
        document.addEventListener('DOMContentLoaded', function() {
            actualizarDatosConciliacion();
        });
    </script>
</body>
</html>