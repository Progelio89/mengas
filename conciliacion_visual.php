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

// Inicializar variables
$pagos = [];
$movimientos = [];
$error = '';
$success = '';

// FUNCIÓN PARA CONCILIAR
function conciliarRegistros($conn, $pago_id, $movimiento_id, $usuario_id) {
    if (sqlsrv_begin_transaction($conn) === false) {
        return false;
    }
    
    try {
        // 1. Actualizar pago
        $sql_pago = "UPDATE pagos SET estado = 'conciliado', fecha_conciliacion = GETDATE(), id_movimiento_conciliado = ? WHERE id = ?";
        $stmt_pago = sqlsrv_query($conn, $sql_pago, array($movimiento_id, $pago_id));
        if ($stmt_pago === false) return false;
        
        // 2. Actualizar movimiento bancario
        $sql_mov = "UPDATE movimientos_banco SET conciliado = 1, fecha_conciliacion = GETDATE(), id_pago_conciliado = ? WHERE id = ?";
        $stmt_mov = sqlsrv_query($conn, $sql_mov, array($pago_id, $movimiento_id));
        if ($stmt_mov === false) return false;
        
        // 3. Registrar en tabla de conciliaciones
        $sql_conc = "INSERT INTO conciliaciones (pago_id, movimiento_banco_id, usuario_id, fecha_conciliacion) 
                     VALUES (?, ?, ?, GETDATE())";
        $stmt_conc = sqlsrv_query($conn, $sql_conc, array($pago_id, $movimiento_id, $usuario_id));
        if ($stmt_conc === false) return false;
        
        sqlsrv_commit($conn);
        return true;
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        return false;
    }
}

// PROCESAR CONCILIACIÓN
if ($_POST) {
    if (isset($_POST['conciliar_individual'])) {
        $pago_id = $_POST['pago_id'];
        $movimiento_id = $_POST['movimiento_id'];
        
        if (conciliarRegistros($conn, $pago_id, $movimiento_id, $usuario_id)) {
            $success = "Registros conciliados exitosamente";
        } else {
            $error = "Error al conciliar los registros";
        }
    }
    
    if (isset($_POST['conciliar_multiple'])) {
        if (isset($_POST['pares_conciliacion'])) {
            $conciliados = 0;
            foreach ($_POST['pares_conciliacion'] as $par) {
                list($pago_id, $movimiento_id) = explode('|', $par);
                if (conciliarRegistros($conn, $pago_id, $movimiento_id, $usuario_id)) {
                    $conciliados++;
                }
            }
            $success = "$conciliados pares conciliados exitosamente";
        } else {
            $error = "Debe seleccionar al menos un par para conciliar";
        }
    }
}

// CARGAR DATOS
function cargarDatosConciliacion($conn, &$pagos, &$movimientos) {
    // Pagos no conciliados
    $sql_pagos = "SELECT p.*, u.nombre as cliente_nombre 
                  FROM pagos p 
                  LEFT JOIN usuarios u ON p.usuario_id = u.id 
                  WHERE p.estado IN ('pendiente', 'aprobado') AND p.id_movimiento_conciliado IS NULL
                  ORDER BY p.fecha_pago DESC";
    
    $stmt_pagos = sqlsrv_query($conn, $sql_pagos);
    if ($stmt_pagos !== false) {
        while ($row = sqlsrv_fetch_array($stmt_pagos, SQLSRV_FETCH_ASSOC)) {
            // Calcular monto en Bs si es USD
            if ($row['moneda'] == 'USD' && $row['tasa_cambio'] > 0) {
                $row['monto_bs'] = $row['monto'] * $row['tasa_cambio'];
            } else {
                $row['monto_bs'] = $row['monto'];
            }
            $pagos[] = $row;
        }
    }

    // Movimientos no conciliados  
    $sql_movimientos = "SELECT * FROM movimientos_banco 
                       WHERE conciliado = 0 
                       ORDER BY fecha_movimiento DESC, id DESC";
    
    $stmt_movimientos = sqlsrv_query($conn, $sql_movimientos);
    if ($stmt_movimientos !== false) {
        while ($row = sqlsrv_fetch_array($stmt_movimientos, SQLSRV_FETCH_ASSOC)) {
            $movimientos[] = $row;
        }
    }
}

cargarDatosConciliacion($conn, $pagos, $movimientos);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conciliación Visual - Sistema Mengas</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .conciliacion-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin: 20px 0;
        }
        .panel {
            border: 1px solid #dee2e6;
            border-radius: 10px;
            background: white;
            max-height: 70vh;
            overflow-y: auto;
        }
        .panel-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 10px 10px 0 0;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .registro-item {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }
        .registro-item:hover {
            background-color: #f8f9fa;
            transform: translateX(5px);
        }
        .registro-item.selected {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border-left: 4px solid #2196f3;
            box-shadow: 0 2px 8px rgba(33, 150, 243, 0.2);
        }
        .registro-item.matched {
            background: linear-gradient(135deg, #e8f5e8 0%, #c8e6c9 100%);
            border-left: 4px solid #4caf50;
        }
        .referencia {
            font-weight: bold;
            font-size: 14px;
            color: #2c3e50;
        }
        .detalles {
            font-size: 12px;
            color: #6c757d;
            margin-top: 5px;
        }
        .monto {
            font-weight: bold;
            font-size: 16px;
        }
        .badge-banco {
            position: absolute;
            top: 10px;
            right: 15px;
            font-size: 10px;
        }
        .acciones-conciliacion {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 5px 25px rgba(0,0,0,0.2);
            z-index: 1000;
            display: none;
        }
        .coincidencia-indicador {
            position: absolute;
            bottom: 10px;
            right: 15px;
            font-size: 12px;
        }
        .resumen-card {
            text-align: center;
            padding: 20px;
            border-radius: 10px;
            color: white;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .resumen-pendientes { background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%); }
        .resumen-movimientos { background: linear-gradient(135deg, #a1c4fd 0%, #c2e9fb 100%); }
        .resumen-conciliados { background: linear-gradient(135deg, #84fab0 0%, #8fd3f4 100%); }
        .conector {
            position: absolute;
            right: -10px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 2px;
            background: #28a745;
            display: none;
        }
        .registro-item.selected .conector {
            display: block;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <!-- Navbar -->
        <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
            <div class="container">
                <a class="navbar-brand" href="#">
                    <i class="fas fa-balance-scale"></i> Conciliación Visual
                </a>
                <div class="navbar-nav ms-auto">
                    <span class="navbar-text me-3">
                        <i class="fas fa-user"></i> <?php echo $_SESSION['nombre']; ?>
                    </span>
                    <a class="btn btn-outline-light btn-sm" href="index.php">
                        <i class="fas fa-arrow-left"></i> Volver al Dashboard
                    </a>
                </div>
            </div>
        </nav>

        <div class="container mt-4">
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Resumen -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="resumen-card resumen-pendientes">
                        <i class="fas fa-clock fa-3x mb-3"></i>
                        <h3><?php echo count($pagos); ?></h3>
                        <p class="mb-0">Pagos Pendientes</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="resumen-card resumen-movimientos">
                        <i class="fas fa-university fa-3x mb-3"></i>
                        <h3><?php echo count($movimientos); ?></h3>
                        <p class="mb-0">Movimientos Banco</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="resumen-card resumen-conciliados">
                        <i class="fas fa-check-double fa-3x mb-3"></i>
                        <h3 id="contador-seleccionados">0</h3>
                        <p class="mb-0">Pares Seleccionados</p>
                    </div>
                </div>
            </div>

            <!-- Instrucciones -->
            <div class="alert alert-info">
                <h5><i class="fas fa-info-circle"></i> Instrucciones de Conciliación</h5>
                <p class="mb-0">
                    1. Selecciona un <strong>pago del sistema</strong> (columna izquierda)<br>
                    2. Selecciona un <strong>movimiento bancario</strong> (columna derecha)<br>
                    3. Haz clic en <strong>"Conciliar Par Seleccionado"</strong><br>
                    O selecciona múltiples pares y usa <strong>"Conciliar Múltiples"</strong>
                </p>
            </div>

            <!-- Panel de Conciliación Visual -->
            <form method="POST" id="formConciliacion">
                <div class="conciliacion-container">
                    
                    <!-- COLUMNA IZQUIERDA: PAGOS DEL SISTEMA -->
                    <div class="panel">
                        <div class="panel-header">
                            <h4 class="mb-0">
                                <i class="fas fa-database"></i> Pagos del Sistema
                                <span class="badge bg-light text-dark"><?php echo count($pagos); ?></span>
                            </h4>
                        </div>
                        <div class="panel-body p-0">
                            <?php foreach ($pagos as $pago): 
                                $fecha_pago = $pago['fecha_pago'] instanceof DateTime ? 
                                    $pago['fecha_pago']->format('d/m/Y') : 
                                    date('d/m/Y', strtotime($pago['fecha_pago']));
                                
                                $monto_mostrar = ($pago['moneda'] == 'USD') ? 
                                    '$' . number_format($pago['monto'], 2) . ' (Bs ' . number_format($pago['monto_bs'], 2) . ')' : 
                                    'Bs ' . number_format($pago['monto'], 2);
                            ?>
                            <div class="registro-item pago-item" 
                                 data-pago-id="<?php echo $pago['id']; ?>"
                                 data-monto="<?php echo $pago['monto_bs']; ?>"
                                 data-referencia="<?php echo $pago['referencia']; ?>"
                                 onclick="seleccionarPago(this)">
                                
                                <div class="referencia">
                                    <i class="fas fa-receipt text-primary"></i>
                                    <?php echo $pago['referencia']; ?>
                                </div>
                                
                                <div class="detalles">
                                    <i class="fas fa-money-bill-wave"></i>
                                    <strong class="monto"><?php echo $monto_mostrar; ?></strong><br>
                                    
                                    <i class="fas fa-calendar"></i>
                                    Fecha: <?php echo $fecha_pago; ?><br>
                                    
                                    <i class="fas fa-user"></i>
                                    Cliente: <?php echo $pago['cliente_nombre'] ?? 'N/A'; ?><br>
                                    
                                    <i class="fas fa-tag"></i>
                                    Estado: <span class="badge bg-warning"><?php echo ucfirst($pago['estado']); ?></span>
                                </div>
                                
                                <div class="form-check mt-2">
                                    <input class="form-check-input checkbox-pago" type="checkbox" 
                                           name="pares_conciliacion[]" 
                                           value="<?php echo $pago['id']; ?>|"
                                           style="display: none;">
                                </div>
                                
                                <div class="conector"></div>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php if (empty($pagos)): ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="fas fa-receipt fa-3x mb-3"></i>
                                    <h5>No hay pagos pendientes</h5>
                                    <p>Todos los pagos están conciliados</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- COLUMNA DERECHA: MOVIMIENTOS BANCARIOS -->
                    <div class="panel">
                        <div class="panel-header">
                            <h4 class="mb-0">
                                <i class="fas fa-university"></i> Movimientos Bancarios
                                <span class="badge bg-light text-dark"><?php echo count($movimientos); ?></span>
                            </h4>
                        </div>
                        <div class="panel-body p-0">
                            <?php foreach ($movimientos as $movimiento): 
                                $fecha_mov = $movimiento['fecha_movimiento'] instanceof DateTime ? 
                                    $movimiento['fecha_movimiento']->format('d/m/Y') : 
                                    date('d/m/Y', strtotime($movimiento['fecha_movimiento']));
                                
                                // Determinar color del badge según el banco
                                $badge_class = 'bg-primary';
                                if (strpos(strtolower($movimiento['banco']), 'provincial') !== false) $badge_class = 'bg-danger';
                                if (strpos(strtolower($movimiento['banco']), 'venezuela') !== false) $badge_class = 'bg-success';
                                if (strpos(strtolower($movimiento['banco']), 'banesco') !== false) $badge_class = 'bg-info';
                                if (strpos(strtolower($movimiento['banco']), 'mercantil') !== false) $badge_class = 'bg-warning';
                            ?>
                            <div class="registro-item movimiento-item" 
                                 data-movimiento-id="<?php echo $movimiento['id']; ?>"
                                 data-monto="<?php echo $movimiento['monto']; ?>"
                                 data-referencia="<?php echo $movimiento['referencia']; ?>"
                                 onclick="seleccionarMovimiento(this)">
                                
                                <div class="referencia">
                                    <i class="fas fa-file-invoice-dollar text-success"></i>
                                    <?php echo $movimiento['referencia']; ?>
                                    <span class="badge <?php echo $badge_class; ?> badge-banco">
                                        <?php echo $movimiento['banco']; ?>
                                    </span>
                                </div>
                                
                                <div class="detalles">
                                    <i class="fas fa-money-bill-wave"></i>
                                    <strong class="monto">Bs <?php echo number_format($movimiento['monto'], 2); ?></strong><br>
                                    
                                    <i class="fas fa-calendar"></i>
                                    Fecha: <?php echo $fecha_mov; ?><br>
                                    
                                    <i class="fas fa-file-alt"></i>
                                    <?php echo substr($movimiento['descripcion'], 0, 50); ?>...
                                </div>
                                
                                <div class="form-check mt-2">
                                    <input class="form-check-input checkbox-movimiento" type="checkbox" 
                                           name="pares_conciliacion[]" 
                                           value="|<?php echo $movimiento['id']; ?>"
                                           style="display: none;">
                                </div>
                                
                                <!-- Indicador de coincidencia -->
                                <div class="coincidencia-indicador" style="display: none;">
                                    <span class="badge bg-success">COINCIDE</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php if (empty($movimientos)): ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="fas fa-file-excel fa-3x mb-3"></i>
                                    <h5>No hay movimientos bancarios</h5>
                                    <p>Cargue un archivo del banco para comenzar</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Panel de Acciones de Conciliación -->
                <div class="acciones-conciliacion" id="accionesConciliacion">
                    <div class="text-center">
                        <h5 class="mb-3">
                            <i class="fas fa-link"></i> Conciliar Registros
                        </h5>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Pago Seleccionado:</strong>
                                <div id="infoPago" class="small text-muted">Ninguno</div>
                            </div>
                            <div class="col-md-6">
                                <strong>Movimiento Seleccionado:</strong>
                                <div id="infoMovimiento" class="small text-muted">Ninguno</div>
                            </div>
                        </div>
                        
                        <div class="btn-group">
                            <button type="submit" name="conciliar_individual" class="btn btn-success">
                                <i class="fas fa-check-circle"></i> Conciliar Par Seleccionado
                            </button>
                            <button type="submit" name="conciliar_multiple" class="btn btn-primary">
                                <i class="fas fa-layer-group"></i> Conciliar Múltiples (<span id="contadorPares">0</span>)
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="limpiarSeleccion()">
                                <i class="fas fa-times"></i> Limpiar
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Campos ocultos para conciliación individual -->
                <input type="hidden" name="pago_id" id="inputPagoId">
                <input type="hidden" name="movimiento_id" id="inputMovimientoId">
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let pagoSeleccionado = null;
        let movimientoSeleccionado = null;
        let paresSeleccionados = new Set();

        function seleccionarPago(elemento) {
            // Limpiar selección anterior
            document.querySelectorAll('.pago-item.selected').forEach(item => {
                item.classList.remove('selected');
            });
            
            // Seleccionar nuevo
            elemento.classList.add('selected');
            pagoSeleccionado = elemento;
            
            // Actualizar información
            const referencia = elemento.dataset.referencia;
            const monto = elemento.dataset.monto;
            document.getElementById('infoPago').innerHTML = 
                `<strong>${referencia}</strong><br>Bs ${parseFloat(monto).toFixed(2)}`;
            document.getElementById('inputPagoId').value = elemento.dataset.pagoId;
            
            // Buscar coincidencias
            buscarCoincidencias();
            mostrarAcciones();
        }

        function seleccionarMovimiento(elemento) {
            // Limpiar selección anterior
            document.querySelectorAll('.movimiento-item.selected').forEach(item => {
                item.classList.remove('selected');
            });
            
            // Seleccionar nuevo
            elemento.classList.add('selected');
            movimientoSeleccionado = elemento;
            
            // Actualizar información
            const referencia = elemento.dataset.referencia;
            const monto = elemento.dataset.monto;
            document.getElementById('infoMovimiento').innerHTML = 
                `<strong>${referencia}</strong><br>Bs ${parseFloat(monto).toFixed(2)}`;
            document.getElementById('inputMovimientoId').value = elemento.dataset.movimientoId;
            
            mostrarAcciones();
        }

        function buscarCoincidencias() {
            if (!pagoSeleccionado) return;
            
            const montoPago = parseFloat(pagoSeleccionado.dataset.monto);
            const referenciaPago = pagoSeleccionado.dataset.referencia;
            
            document.querySelectorAll('.movimiento-item').forEach(mov => {
                const montoMov = parseFloat(mov.dataset.monto);
                const referenciaMov = mov.dataset.referencia;
                
                // Verificar coincidencia (mismo monto ± 1 Bs y referencia similar)
                const coincideMonto = Math.abs(montoPago - montoMov) < 1.0;
                const coincideReferencia = referenciaMov.includes(referenciaPago.substring(0, 6)) || 
                                         referenciaPago.includes(referenciaMov.substring(0, 6));
                
                if (coincideMonto && coincideReferencia) {
                    mov.classList.add('matched');
                    mov.querySelector('.coincidencia-indicador').style.display = 'block';
                } else {
                    mov.classList.remove('matched');
                    mov.querySelector('.coincidencia-indicador').style.display = 'none';
                }
            });
        }

        function mostrarAcciones() {
            if (pagoSeleccionado && movimientoSeleccionado) {
                document.getElementById('accionesConciliacion').style.display = 'block';
                
                // Crear par para conciliación múltiple
                const parId = `${pagoSeleccionado.dataset.pagoId}|${movimientoSeleccionado.dataset.movimientoId}`;
                
                // Buscar checkboxes y actualizar
                const checkboxPago = pagoSeleccionado.querySelector('.checkbox-pago');
                const checkboxMov = movimientoSeleccionado.querySelector('.checkbox-movimiento');
                
                if (checkboxPago && checkboxMov) {
                    checkboxPago.value = `${pagoSeleccionado.dataset.pagoId}|${movimientoSeleccionado.dataset.movimientoId}`;
                    checkboxMov.value = `${pagoSeleccionado.dataset.pagoId}|${movimientoSeleccionado.dataset.movimientoId}`;
                    
                    if (!checkboxPago.checked) {
                        checkboxPago.checked = true;
                        checkboxMov.checked = true;
                        paresSeleccionados.add(parId);
                    }
                    
                    actualizarContadorPares();
                }
            }
        }

        function actualizarContadorPares() {
            const checkboxes = document.querySelectorAll('input[name="pares_conciliacion[]"]:checked');
            document.getElementById('contadorPares').textContent = checkboxes.length;
            document.getElementById('contador-seleccionados').textContent = checkboxes.length;
        }

        function limpiarSeleccion() {
            pagoSeleccionado = null;
            movimientoSeleccionado = null;
            
            document.querySelectorAll('.selected').forEach(item => {
                item.classList.remove('selected');
            });
            
            document.querySelectorAll('.matched').forEach(item => {
                item.classList.remove('matched');
                item.querySelector('.coincidencia-indicador').style.display = 'none';
            });
            
            document.querySelectorAll('input[name="pares_conciliacion[]"]').forEach(checkbox => {
                checkbox.checked = false;
            });
            
            document.getElementById('accionesConciliacion').style.display = 'none';
            document.getElementById('infoPago').textContent = 'Ninguno';
            document.getElementById('infoMovimiento').textContent = 'Ninguno';
            document.getElementById('inputPagoId').value = '';
            document.getElementById('inputMovimientoId').value = '';
            
            paresSeleccionados.clear();
            actualizarContadorPares();
        }

        // Confirmación antes de conciliar
        document.getElementById('formConciliacion').addEventListener('submit', function(e) {
            if (e.submitter.name === 'conciliar_individual') {
                if (!confirm('¿Está seguro de que desea conciliar este par de registros?')) {
                    e.preventDefault();
                }
            } else if (e.submitter.name === 'conciliar_multiple') {
                const cantidad = document.querySelectorAll('input[name="pares_conciliacion[]"]:checked').length;
                if (cantidad === 0) {
                    alert('Debe seleccionar al menos un par para conciliar');
                    e.preventDefault();
                } else if (!confirm(`¿Está seguro de que desea conciliar ${cantidad} pares de registros?`)) {
                    e.preventDefault();
                }
            }
        });

        // Inicializar
        document.addEventListener('DOMContentLoaded', function() {
            actualizarContadorPares();
        });
    </script>
</body>
</html>