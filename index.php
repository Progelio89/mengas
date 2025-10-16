<?php
session_start();
require_once 'config/database.php';

// Verificar si el usuario está logueado
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

// Funciones para calcular estados según las nuevas reglas
function calcularEstado($fecha_pago) {
    $hoy = new DateTime();
    $fechaPago = new DateTime($fecha_pago);
    
    // Último día del mes + 5 días de prórroga
    $ultimoDiaMes = new DateTime($fechaPago->format('Y-m-t'));
    $fechaLimite = clone $ultimoDiaMes;
    $fechaLimite->modify('+5 days');
    
    if ($hoy <= $fechaLimite) {
        return 'pendiente';
    } else {
        return 'moroso';
    }
}

// Función para calcular fecha de vencimiento (último día del mes + 5 días)
function calcularFechaVencimiento($fecha_pago) {
    $fecha = new DateTime($fecha_pago);
    $ultimoDiaMes = new DateTime($fecha->format('Y-m-t'));
    $ultimoDiaMes->modify('+5 days'); // 5 días de prórroga
    return $ultimoDiaMes->format('d/m/Y');
}

$usuario_id = $_SESSION['usuario_id'];
$rol = $_SESSION['rol'];

try {
    // Conectar a la base de datos (empresa A por defecto)
    $db = new Database('A');
    $conn = $db->getConnection();
    
    // Obtener tasa BCV actual para conversiones
    $tasa_sql = "SELECT TOP 1 tasa_usd FROM tasas_bcv WHERE activa = 1 ORDER BY fecha_actualizacion DESC";
    $tasa_stmt = sqlsrv_query($conn, $tasa_sql);
    $tasa_row = sqlsrv_fetch_array($tasa_stmt, SQLSRV_FETCH_ASSOC);
    $tasa_actual = $tasa_row ? $tasa_row['tasa_usd'] : 0;
    sqlsrv_free_stmt($tasa_stmt);
    
    // Total pagos
    $sql = "SELECT COUNT(*) as total FROM pagos";
    if ($rol != 'admin') {
        $sql .= " WHERE usuario_id = ?";
        $stmt = sqlsrv_query($conn, $sql, array($usuario_id));
    } else {
        $stmt = sqlsrv_query($conn, $sql);
    }
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $total_pagos = $row['total'];
    sqlsrv_free_stmt($stmt);
    
    // Pagos pendientes según nuevas reglas
    $sql_pendientes = "SELECT referencia, fecha_pago FROM pagos";
    if ($rol != 'admin') {
        $sql_pendientes .= " WHERE usuario_id = ?";
        $stmt_pendientes = sqlsrv_query($conn, $sql_pendientes, array($usuario_id));
    } else {
        $stmt_pendientes = sqlsrv_query($conn, $sql_pendientes);
    }

    $pagos_pendientes = 0;
    while ($row = sqlsrv_fetch_array($stmt_pendientes, SQLSRV_FETCH_ASSOC)) {
        // Obtener la fecha como string y formatearla correctamente
        $fecha_pago_str = $row['fecha_pago'] instanceof DateTime ? 
            $row['fecha_pago']->format('Y-m-d') : 
            date('Y-m-d', strtotime($row['fecha_pago']));
        
        $estado = calcularEstado($fecha_pago_str);
        if ($estado == 'pendiente') {
            $pagos_pendientes++;
        }
    }
    sqlsrv_free_stmt($stmt_pendientes);

    // Pagos morosos según nuevas reglas
    $sql_morosos = "SELECT referencia, fecha_pago FROM pagos";
    if ($rol != 'admin') {
        $sql_morosos .= " WHERE usuario_id = ?";
        $stmt_morosos = sqlsrv_query($conn, $sql_morosos, array($usuario_id));
    } else {
        $stmt_morosos = sqlsrv_query($conn, $sql_morosos);
    }

    $pagos_morosos = 0;
    while ($row = sqlsrv_fetch_array($stmt_morosos, SQLSRV_FETCH_ASSOC)) {
        // Obtener la fecha como string y formatearla correctamente
        $fecha_pago_str = $row['fecha_pago'] instanceof DateTime ? 
            $row['fecha_pago']->format('Y-m-d') : 
            date('Y-m-d', strtotime($row['fecha_pago']));
        
        $estado = calcularEstado($fecha_pago_str);
        if ($estado == 'moroso') {
            $pagos_morosos++;
        }
    }
    sqlsrv_free_stmt($stmt_morosos);
    
    // Calcular totales en ambas monedas
    $sql_totales = "SELECT 
        SUM(CASE WHEN moneda = 'USD' THEN monto ELSE 0 END) as total_usd,
        SUM(CASE WHEN moneda = 'BS' THEN monto ELSE 0 END) as total_bs,
        SUM(monto_bs) as total_bs_equivalente
        FROM pagos";
    
    if ($rol != 'admin') {
        $sql_totales .= " WHERE usuario_id = ?";
        $stmt_totales = sqlsrv_query($conn, $sql_totales, array($usuario_id));
    } else {
        $stmt_totales = sqlsrv_query($conn, $sql_totales);
    }
    
    $totales = sqlsrv_fetch_array($stmt_totales, SQLSRV_FETCH_ASSOC);
    $total_usd = $totales['total_usd'] ?: 0;
    $total_bs = $totales['total_bs'] ?: 0;
    $total_bs_equivalente = $totales['total_bs_equivalente'] ?: 0;
    sqlsrv_free_stmt($stmt_totales);
    
    // Calcular equivalentes
    $total_usd_equivalente = $tasa_actual > 0 ? $total_bs_equivalente / $tasa_actual : 0;
    
    // Últimos pagos
    $sql = "SELECT TOP 10 p.*, u.nombre as usuario_nombre 
            FROM pagos p 
            JOIN usuarios u ON p.usuario_id = u.id";
    if ($rol != 'admin') {
        $sql .= " WHERE p.usuario_id = ?";
    }
    $sql .= " ORDER BY p.fecha_registro DESC";
    
    if ($rol != 'admin') {
        $stmt = sqlsrv_query($conn, $sql, array($usuario_id));
    } else {
        $stmt = sqlsrv_query($conn, $sql);
    }
    
    $ultimos_pagos = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $ultimos_pagos[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    
} catch (Exception $e) {
    $error = "Error al cargar datos: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Gestión de Pagos</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <link href="css/style.css" rel="stylesheet">
  <style>
    .status.moroso {
        background-color: #e74c3c;
        color: white;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: bold;
    }

    .status.pendiente {
        background-color: #f39c12;
        color: white;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: bold;
    }
    
    .status.aprobado {
        background-color: #27ae60;
        color: white;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: bold;
    }
  </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-money-bill-wave"></i> Sistema Pagos</h2>
                <small>Base: sistema_pagos</small>
            </div>
            <ul class="sidebar-menu">
                <li><a href="index.php" class="active"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="registrar_pago.php"><i class="fas fa-plus-circle"></i> Registrar Pago</a></li>
                <li><a href="pagos.php"><i class="fas fa-list"></i> Mis Pagos</a></li>
                <?php if ($_SESSION['rol'] == 'admin' || $_SESSION['rol'] == 'consolidador'): ?>
                <li><a href="conciliacion.php"><i class="fas fa-exchange-alt"></i> Conciliación</a></li>
                <li><a href="usuarios.php"><i class="fas fa-users"></i> Usuarios</a></li>
                <?php endif; ?>
                <?php if ($_SESSION['rol'] == 'admin'): ?>
                <li><a href="configuracion.php"><i class="fas fa-cog"></i> Configuración</a></li>
                <?php endif; ?>
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="top-nav">
                <h3>Dashboard</h3>
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div class="user-info">
                        <span>Bienvenido, <?php echo $_SESSION['nombre']; ?></span>
                        <span class="badge"><?php echo ucfirst($_SESSION['rol']); ?></span>
                    </div>
                    <div class="server-info">
                        Servidor: <?php echo $db->getCurrentServer(); ?>
                    </div>
                </div>
            </div>

            <div class="content">
                <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>

                <!-- Estadísticas -->
                <div class="stats-container">
                    <div class="stat-card">
                        <h3>Total de Pagos</h3>
                        <div class="value"><?php echo $total_pagos; ?></div>
                        <div class="equivalente">Transacciones registradas</div>
                    </div>
                    
                    <div class="stat-card currency">
                        <h3>Total en Bolívares</h3>
                        <div class="value monto-bs">Bs <?php echo number_format($total_bs_equivalente, 2, ',', '.'); ?></div>
                        <div class="equivalente">
                            ≈ $<?php echo number_format($total_usd_equivalente, 2, ',', '.'); ?> USD
                        </div>
                    </div>
                    
                    <div class="stat-card currency">
                        <h3>Total en Dólares</h3>
                        <div class="value monto-usd">$<?php echo number_format($total_usd, 2, ',', '.'); ?> USD</div>
                        <div class="equivalente">
                            ≈ Bs <?php echo number_format($total_usd * $tasa_actual, 2, ',', '.'); ?>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <h3>Tasa BCV Actual</h3>
                        <div class="value" id="tasa-bcv">Bs <?php echo number_format($tasa_actual, 2, ',', '.'); ?></div>
                        <div class="equivalente" id="tasa-fecha">Actualizada: <?php echo date('d/m/Y'); ?></div>
                    </div>
                    
                    <div class="stat-card pending">
                        <h3>Pagos Pendientes</h3>
                        <div class="value"><?php echo $pagos_pendientes; ?></div>
                        <div class="equivalente">En período válido</div>
                    </div>
                    
                    <div class="stat-card overdue">
                        <h3>Pagos Morosos</h3>
                        <div class="value"><?php echo $pagos_morosos; ?></div>
                        <div class="equivalente">Fuera de plazo</div>
                    </div>
                </div>

                <!-- Últimos Pagos -->
                <div class="card">
                    <div class="card-header">
                        <h4>Últimos Pagos Registrados</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Referencia</th>
                                        <th>Monto Original</th>
                                        <th>Equivalente</th>
                                        <th>Moneda</th>
                                        <th>Fecha Pago</th>
                                        <th>Fecha Venc.</th>
                                        <th>Estado</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($ultimos_pagos) > 0): ?>
                                        <?php foreach ($ultimos_pagos as $pago): 
                                            // Formatear fecha de pago
                                            $fecha_pago = $pago['fecha_pago'] instanceof DateTime ? 
                                                $pago['fecha_pago']->format('d/m/Y') : 
                                                date('d/m/Y', strtotime($pago['fecha_pago']));
                                            
                                            // Obtener fecha como string para cálculos
                                            $fecha_pago_str = $pago['fecha_pago'] instanceof DateTime ? 
                                                $pago['fecha_pago']->format('Y-m-d') : 
                                                date('Y-m-d', strtotime($pago['fecha_pago']));
                                            
                                            // Calcular estado actual según nuevas reglas
                                            $estado_actual = calcularEstado($fecha_pago_str);
                                            
                                            // Calcular fecha de vencimiento según nuevas reglas
                                            $fecha_vencimiento = calcularFechaVencimiento($fecha_pago_str);
                                            
                                            // Mostrar monto original y equivalente
                                            if ($pago['moneda'] == 'USD') {
                                                $monto_original = '$' . number_format($pago['monto'], 2, ',', '.');
                                                $monto_equivalente = 'Bs ' . number_format($pago['monto_bs'], 2, ',', '.');
                                                $clase_original = 'monto-usd';
                                            } else {
                                                $monto_original = 'Bs ' . number_format($pago['monto'], 2, ',', '.');
                                                $monto_equivalente = '$' . number_format($pago['monto_bs'] / $tasa_actual, 2, ',', '.');
                                                $clase_original = 'monto-bs';
                                            }
                                        ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($pago['referencia']); ?></strong></td>
                                            <td>
                                                <span class="<?php echo $clase_original; ?>">
                                                    <?php echo $monto_original; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="equivalente-monto">
                                                    <?php echo $monto_equivalente; ?>
                                                </span>
                                            </td>
                                            <td><?php echo $pago['moneda']; ?></td>
                                            <td><?php echo $fecha_pago; ?></td>
                                            <td><?php echo $fecha_vencimiento; ?></td>
                                            <td>
                                                <span class="status <?php echo $estado_actual; ?>">
                                                    <?php echo ucfirst($estado_actual); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="ver_pago.php?id=<?php echo $pago['id']; ?>" class="btn btn-primary">
                                                    <i class="fas fa-eye"></i> Ver
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" style="text-align: center; padding: 40px;">
                                                <i class="fas fa-receipt" style="font-size: 48px; color: #bdc3c7; margin-bottom: 15px;"></i>
                                                <p>No hay pagos registrados</p>
                                                <a href="registrar_pago.php" class="btn btn-primary">
                                                    <i class="fas fa-plus"></i> Registrar Primer Pago
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Cargar tasa BCV actual
        function cargarTasaBCV() {
            fetch('api/tasa_bcv.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('tasa-bcv').textContent = 'Bs ' + data.tasa.toFixed(2).replace('.', ',');
                        document.getElementById('tasa-fecha').textContent = 'Actualizada: ' + new Date().toLocaleDateString('es-ES');
                    } else {
                        document.getElementById('tasa-bcv').textContent = 'Bs <?php echo number_format($tasa_actual, 2, ',', '.'); ?>';
                        document.getElementById('tasa-fecha').textContent = 'Actualizada: <?php echo date('d/m/Y'); ?>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('tasa-bcv').textContent = 'Bs <?php echo number_format($tasa_actual, 2, ',', '.'); ?>';
                    document.getElementById('tasa-fecha').textContent = 'Actualizada: <?php echo date('d/m/Y'); ?>';
                });
        }

        // Cargar tasa al iniciar
        cargarTasaBCV();
        
        // Actualizar cada 5 minutos
        setInterval(cargarTasaBCV, 300000);
    </script>
</body>
</html>