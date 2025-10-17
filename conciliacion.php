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
$conciliados = [];
$error = '';
$success = '';

// FUNCIONES AUXILIARES
function extraerReferencia($descripcion) {
    $patrones = [
        '/DRV(\d+)/',
        '/OB\s+V?(\d+)/i',
        '/REF\s*\.?\s*(\d+)/i',
        '/PAGO\s+(\d+)/i',
        '/ABO\.?\s*(\w+)/i',
        '/CAR\.?\s*(\w+)/i',
        '/(\d{8,12})/',
    ];
    
    foreach ($patrones as $patron) {
        if (preg_match($patron, $descripcion, $matches)) {
            return trim($matches[1]);
        }
    }
    
    $limpio = preg_replace('/[^0-9A-Z]/', '', strtoupper($descripcion));
    return substr($limpio, 0, 20) ?: 'SIN_REF';
}

function coinciden($pago, $movimiento) {
    $ref_pago = preg_replace('/[^0-9]/', '', $pago['referencia']);
    $ref_mov = preg_replace('/[^0-9]/', '', $movimiento['referencia']);
    
    $coincide_ref = false;
    if ($ref_pago === $ref_mov) {
        $coincide_ref = true;
    } else if (strlen($ref_pago) >= 6 && strlen($ref_mov) >= 6) {
        $coincide_ref = substr($ref_pago, -6) === substr($ref_mov, -6);
    }
    
    $monto_pago = isset($pago['monto_bs_calculado']) ? $pago['monto_bs_calculado'] : $pago['monto'];
    $coincide_monto = abs($monto_pago - $movimiento['monto']) < 1.00;
    
    return $coincide_ref && $coincide_monto;
}

// PROCESAR ARCHIVO EXCEL DEL BANCO
if (isset($_POST['subir_movimientos'])) {
    if (isset($_FILES['archivo_banco']) && $_FILES['archivo_banco']['error'] == 0) {
        $banco_nombre = $_POST['banco_nombre'];
        $archivo_tmp = $_FILES['archivo_banco']['tmp_name'];
        $nombre_archivo = $_FILES['archivo_banco']['name'];
        
        if (($handle = fopen($archivo_tmp, 'r')) !== FALSE) {
            $fila = 0;
            $movimientos_insertados = 0;
            
            while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
                $fila++;
                if ($fila == 1) continue;
                
                if (count($data) >= 4) {
                    $fecha = DateTime::createFromFormat('Y-m-d', trim($data[0]));
                    if (!$fecha) {
                        $fecha = DateTime::createFromFormat('d/m/Y', trim($data[0]));
                    }
                    $fecha_str = $fecha ? $fecha->format('Y-m-d') : date('Y-m-d');
                    
                    $descripcion = trim($data[1]);
                    $monto_limpio = str_replace(',', '.', str_replace('.', '', trim($data[2])));
                    $monto = floatval($monto_limpio);
                    $saldo_limpio = str_replace(',', '.', str_replace('.', '', trim($data[3])));
                    $saldo = floatval($saldo_limpio);
                    
                    if ($monto != 0) {
                        $sql = "INSERT INTO movimientos_banco (referencia, monto, banco, fecha_movimiento, descripcion, saldo, archivo_origen) 
                                VALUES (?, ?, ?, ?, ?, ?, ?)";
                        $referencia = extraerReferencia($descripcion);
                        $params = array($referencia, $monto, $banco_nombre, $fecha_str, $descripcion, $saldo, $nombre_archivo);
                        
                        $stmt = sqlsrv_query($conn, $sql, $params);
                        if ($stmt !== false) {
                            $movimientos_insertados++;
                        }
                    }
                }
            }
            fclose($handle);
            
            if ($movimientos_insertados > 0) {
                $success = "$movimientos_insertados movimientos bancarios cargados exitosamente";
            } else {
                $error = "No se pudieron insertar movimientos. Verifica el formato del archivo.";
            }
        }
    }
}

// CONCILIACIÓN MANUAL
if (isset($_POST['conciliar'])) {
    if (isset($_POST['pagos_seleccionados']) && isset($_POST['movimientos_seleccionados'])) {
        $pagos_ids = $_POST['pagos_seleccionados'];
        $movimientos_ids = $_POST['movimientos_seleccionados'];
        
        if (count($pagos_ids) != count($movimientos_ids)) {
            $error = "Debe seleccionar la misma cantidad de pagos y movimientos";
        } else {
            $conciliados = 0;
            
            for ($i = 0; $i < count($pagos_ids); $i++) {
                $pago_id = $pagos_ids[$i];
                $movimiento_id = $movimientos_ids[$i];
                
                // Iniciar transacción
                if (sqlsrv_begin_transaction($conn) !== false) {
                    try {
                        // Actualizar pago
                        $sql_pago = "UPDATE pagos SET estado = 'conciliado', fecha_conciliacion = GETDATE(), id_movimiento_conciliado = ? WHERE id = ?";
                        $stmt_pago = sqlsrv_query($conn, $sql_pago, array($movimiento_id, $pago_id));
                        
                        // Actualizar movimiento
                        $sql_mov = "UPDATE movimientos_banco SET conciliado = 1, fecha_conciliacion = GETDATE(), id_pago_conciliado = ? WHERE id = ?";
                        $stmt_mov = sqlsrv_query($conn, $sql_mov, array($pago_id, $movimiento_id));
                        
                        // Registrar conciliación
                        $sql_conc = "INSERT INTO conciliaciones (pago_id, movimiento_banco_id, usuario_id) VALUES (?, ?, ?)";
                        $stmt_conc = sqlsrv_query($conn, $sql_conc, array($pago_id, $movimiento_id, $usuario_id));
                        
                        if ($stmt_pago !== false && $stmt_mov !== false && $stmt_conc !== false) {
                            sqlsrv_commit($conn);
                            $conciliados++;
                        } else {
                            sqlsrv_rollback($conn);
                        }
                    } catch (Exception $e) {
                        sqlsrv_rollback($conn);
                    }
                }
            }
            
            if ($conciliados > 0) {
                $success = "$conciliados pagos conciliados exitosamente";
            } else {
                $error = "Error al conciliar los pagos";
            }
        }
    } else {
        $error = "Debe seleccionar pagos y movimientos para conciliar";
    }
}

// CARGAR DATOS
function cargarDatosConciliacion($conn, &$pagos, &$movimientos, &$conciliados) {
    // Pagos pendientes
    $sql_pagos = "SELECT p.*, u.nombre as cliente_nombre 
                  FROM pagos p 
                  LEFT JOIN usuarios u ON p.usuario_id = u.id 
                  WHERE p.estado IN ('pendiente', 'aprobado')
                  ORDER BY p.fecha_pago DESC";
    
    $stmt_pagos = sqlsrv_query($conn, $sql_pagos);
    if ($stmt_pagos !== false) {
        while ($row = sqlsrv_fetch_array($stmt_pagos, SQLSRV_FETCH_ASSOC)) {
            $pagos[] = $row;
        }
    }

    // Movimientos no conciliados  
    $sql_movimientos = "SELECT * FROM movimientos_banco WHERE conciliado = 0 ORDER BY fecha_movimiento DESC";
    $stmt_movimientos = sqlsrv_query($conn, $sql_movimientos);
    if ($stmt_movimientos !== false) {
        while ($row = sqlsrv_fetch_array($stmt_movimientos, SQLSRV_FETCH_ASSOC)) {
            $movimientos[] = $row;
        }
    }

    // Conciliados recientes
    $sql_conciliados = "SELECT TOP 10 p.*, u.nombre as cliente_nombre, mb.referencia as ref_banco, mb.monto as monto_banco
                       FROM pagos p
                       LEFT JOIN usuarios u ON p.usuario_id = u.id
                       LEFT JOIN movimientos_banco mb ON p.id_movimiento_conciliado = mb.id
                       WHERE p.estado = 'conciliado'
                       ORDER BY p.fecha_conciliacion DESC";
    
    $stmt_conciliados = sqlsrv_query($conn, $sql_conciliados);
    if ($stmt_conciliados !== false) {
        while ($row = sqlsrv_fetch_array($stmt_conciliados, SQLSRV_FETCH_ASSOC)) {
            $conciliados[] = $row;
        }
    }
}

cargarDatosConciliacion($conn, $pagos, $movimientos, $conciliados);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conciliación Bancaria - Sistema Mengas</title>
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
            border-radius: 0.375rem;
            background: white;
        }
        .panel-header {
            background: #2c3e50;
            color: white;
            padding: 1rem;
            border-radius: 0.375rem 0.375rem 0 0;
        }
        .item {
            padding: 0.75rem;
            border-bottom: 1px solid #dee2e6;
            cursor: pointer;
            transition: background-color 0.15s;
        }
        .item:hover {
            background-color: #f8f9fa;
        }
        .item.selected {
            background-color: #e3f2fd;
            border-left: 4px solid #2196f3;
        }
        .item.coincide {
            background-color: #d4edda;
        }
        .badge-banco {
            font-size: 0.7em;
        }
        .resumen-card {
            text-align: center;
            padding: 1rem;
            border-radius: 0.375rem;
            color: white;
            margin-bottom: 1rem;
        }
        .resumen-pendientes { background: #ffc107; }
        .resumen-movimientos { background: #17a2b8; }
        .resumen-conciliados { background: #28a745; }
        .resumen-coinciden { background: #6f42c1; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 d-md-block bg-dark sidebar collapse">
                <div class="position-sticky pt-3">
                    <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-white">
                        <span>Menú Principal</span>
                    </h6>
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link text-white" href="index.php">
                                <i class="fas fa-home"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white" href="pagos.php">
                                <i class="fas fa-list"></i> Pagos
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active text-white" href="conciliacion.php">
                                <i class="fas fa-exchange-alt"></i> Conciliación
                            </a>
                        </li>
                        <?php if ($_SESSION['rol'] == 'admin'): ?>
                        <li class="nav-item">
                            <a class="nav-link text-white" href="usuarios.php">
                                <i class="fas fa-users"></i> Usuarios
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-exchange-alt"></i> Conciliación Bancaria
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <span class="me-2">Bienvenido, <?php echo $_SESSION['nombre']; ?></span>
                        <span class="badge bg-secondary"><?php echo ucfirst($_SESSION['rol']); ?></span>
                    </div>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>

                <!-- Resumen -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="resumen-card resumen-pendientes">
                            <i class="fas fa-clock fa-2x"></i>
                            <h5><?php echo count($pagos); ?></h5>
                            <small>Pagos Pendientes</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="resumen-card resumen-movimientos">
                            <i class="fas fa-university fa-2x"></i>
                            <h5><?php echo count($movimientos); ?></h5>
                            <small>Movimientos Banco</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="resumen-card resumen-coinciden">
                            <i class="fas fa-check-circle fa-2x"></i>
                            <h5 id="contador-coincidencias">0</h5>
                            <small>Coincidencias</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="resumen-card resumen-conciliados">
                            <i class="fas fa-check-double fa-2x"></i>
                            <h5 id="contador-seleccionados">0</h5>
                            <small>Por Conciliar</small>
                        </div>
                    </div>
                </div>

                <!-- Cargar archivo -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-file-upload"></i> Cargar Estado de Cuenta
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Banco</label>
                                <select name="banco_nombre" class="form-select" required>
                                    <option value="">Seleccionar Banco</option>
                                    <option value="Provincial">Provincial</option>
                                    <option value="Venezuela">Venezuela</option>
                                    <option value="Banesco">Banesco</option>
                                    <option value="Mercantil">Mercantil</option>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Archivo del Banco</label>
                                <input type="file" name="archivo_banco" class="form-control" accept=".csv" required>
                                <small class="form-text text-muted">Formato CSV: Fecha, Descripción, Monto, Saldo</small>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" name="subir_movimientos" class="btn btn-primary w-100">
                                    <i class="fas fa-upload"></i> Cargar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Panel de Conciliación -->
                <form method="POST" id="form-conciliacion">
                    <div class="conciliacion-container">
                        <!-- Pagos del Sistema -->
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-database"></i> Pagos del Sistema
                                    <span class="badge bg-light text-dark"><?php echo count($pagos); ?></span>
                                </h5>
                            </div>
                            <div class="panel-body p-0">
                                <?php 
                                $coincidencias_totales = 0;
                                foreach ($pagos as $pago): 
                                    $fecha_pago = $pago['fecha_pago'] instanceof DateTime ? 
                                        $pago['fecha_pago']->format('d/m/Y') : 
                                        date('d/m/Y', strtotime($pago['fecha_pago']));
                                    
                                    $coincide = false;
                                    $movimientos_coincidentes = [];
                                    foreach ($movimientos as $movimiento) {
                                        if (coinciden($pago, $movimiento)) {
                                            $coincide = true;
                                            $coincidencias_totales++;
                                            $movimientos_coincidentes[] = $movimiento['id'];
                                        }
                                    }
                                ?>
                                <div class="item <?php echo $coincide ? 'coincide' : ''; ?>" 
                                     data-pago-id="<?php echo $pago['id']; ?>"
                                     data-coincide="<?php echo $coincide ? '1' : '0'; ?>"
                                     data-movimientos="<?php echo implode(',', $movimientos_coincidentes); ?>">
                                    
                                    <div class="form-check">
                                        <input class="form-check-input pago-checkbox" type="checkbox" 
                                               name="pagos_seleccionados[]" value="<?php echo $pago['id']; ?>"
                                               onchange="actualizarContadores()">
                                        <label class="form-check-label w-100">
                                            <strong><?php echo $pago['referencia']; ?></strong>
                                            <?php if ($coincide): ?>
                                                <span class="badge bg-success float-end">COINCIDE</span>
                                            <?php endif; ?>
                                            <br>
                                            <small>
                                                Monto: 
                                                <?php echo ($pago['moneda'] == 'USD') ? '$' : 'Bs '; ?>
                                                <?php echo number_format($pago['monto'], 2); ?>
                                                | Fecha: <?php echo $fecha_pago; ?>
                                                <?php if (isset($pago['cliente_nombre'])): ?>
                                                | Cliente: <?php echo $pago['cliente_nombre']; ?>
                                                <?php endif; ?>
                                            </small>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                
                                <?php if (empty($pagos)): ?>
                                    <div class="item text-center text-muted py-4">
                                        <i class="fas fa-receipt fa-2x mb-2"></i>
                                        <p>No hay pagos pendientes</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Movimientos Bancarios -->
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-university"></i> Movimientos Bancarios
                                    <span class="badge bg-light text-dark"><?php echo count($movimientos); ?></span>
                                </h5>
                            </div>
                            <div class="panel-body p-0">
                                <?php foreach ($movimientos as $movimiento): 
                                    $fecha_mov = $movimiento['fecha_movimiento'] instanceof DateTime ? 
                                        $movimiento['fecha_movimiento']->format('d/m/Y') : 
                                        date('d/m/Y', strtotime($movimiento['fecha_movimiento']));
                                ?>
                                <div class="item" data-movimiento-id="<?php echo $movimiento['id']; ?>">
                                    <div class="form-check">
                                        <input class="form-check-input movimiento-checkbox" type="checkbox" 
                                               name="movimientos_seleccionados[]" value="<?php echo $movimiento['id']; ?>"
                                               onchange="actualizarContadores()">
                                        <label class="form-check-label w-100">
                                            <strong><?php echo $movimiento['referencia']; ?></strong>
                                            <span class="badge badge-banco bg-primary float-end"><?php echo $movimiento['banco']; ?></span>
                                            <br>
                                            <small>
                                                Monto: Bs <?php echo number_format($movimiento['monto'], 2); ?>
                                                | Fecha: <?php echo $fecha_mov; ?>
                                            </small>
                                            <br>
                                            <small class="text-muted"><?php echo $movimiento['descripcion']; ?></small>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                
                                <?php if (empty($movimientos)): ?>
                                    <div class="item text-center text-muted py-4">
                                        <i class="fas fa-file-excel fa-2x mb-2"></i>
                                        <p>No hay movimientos cargados</p>
                                        <small>Suba un archivo CSV con los movimientos del banco</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Botones de acción -->
                    <?php if (!empty($pagos) && !empty($movimientos)): ?>
                    <div class="text-center mt-4">
                        <button type="submit" name="conciliar" class="btn btn-success btn-lg">
                            <i class="fas fa-check-double"></i> Conciliar Seleccionados 
                            (<span id="contador-boton">0</span>)
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="seleccionarCoincidencias()">
                            <i class="fas fa-check-circle"></i> Seleccionar Coincidencias
                        </button>
                    </div>
                    <?php endif; ?>
                </form>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function actualizarContadores() {
            const pagosSeleccionados = document.querySelectorAll('.pago-checkbox:checked').length;
            const movimientosSeleccionados = document.querySelectorAll('.movimiento-checkbox:checked').length;
            const coincidencias = document.querySelectorAll('.item[data-coincide="1"]').length;
            
            document.getElementById('contador-seleccionados').textContent = pagosSeleccionados;
            document.getElementById('contador-coincidencias').textContent = coincidencias;
            document.getElementById('contador-boton').textContent = pagosSeleccionados;
            
            // Validar que la cantidad de selecciones sea igual
            if (pagosSeleccionados !== movimientosSeleccionados) {
                document.querySelector('button[name="conciliar"]').disabled = true;
            } else {
                document.querySelector('button[name="conciliar"]').disabled = false;
            }
        }
        
        function seleccionarCoincidencias() {
            document.querySelectorAll('.item[data-coincide="1"] .pago-checkbox').forEach(checkbox => {
                checkbox.checked = true;
            });
            
            // Seleccionar movimientos coincidentes automáticamente
            document.querySelectorAll('.item[data-coincide="1"]').forEach(item => {
                const movimientosIds = item.dataset.movimientos.split(',');
                movimientosIds.forEach(movId => {
                    const movCheckbox = document.querySelector(`.movimiento-checkbox[value="${movId}"]`);
                    if (movCheckbox) {
                        movCheckbox.checked = true;
                    }
                });
            });
            
            actualizarContadores();
        }
        
        // Inicializar
        document.addEventListener('DOMContentLoaded', function() {
            actualizarContadores();
        });
    </script>
</body>
</html>