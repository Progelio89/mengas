<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

$filtro_estado = $_GET['estado'] ?? 'todos';
$filtro_mes = $_GET['mes'] ?? '';

try {
    $db = new Database('A');
    
    // Construir consulta con filtros
    $sql = "SELECT * FROM pagos WHERE usuario_id = ?";
    $params = array($_SESSION['usuario_id']);
    
    if ($filtro_estado != 'todos') {
        $sql .= " AND estado = ?";
        $params[] = $filtro_estado;
    }
    
    if ($filtro_mes) {
        $sql .= " AND MONTH(fecha_pago) = ? AND YEAR(fecha_pago) = ?";
        $params[] = date('m', strtotime($filtro_mes));
        $params[] = date('Y', strtotime($filtro_mes));
    }
    
    $sql .= " ORDER BY fecha_registro DESC";
    
    $pagos = $db->fetchArray($sql, $params);
    
} catch (Exception $e) {
    $error = "Error al cargar pagos: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Pagos - Sistema de Pagos</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .filtros {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .filtro-grid {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 15px;
            align-items: end;
        }
        
        .filtro-group {
            margin-bottom: 0;
        }
        
        .btn-sm {
            padding: 8px 15px;
            font-size: 14px;
        }
        
        .acciones-pago {
            display: flex;
            gap: 5px;
        }
        
        .btn-icon {
            padding: 5px 8px;
            font-size: 12px;
        }
        
        .badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .badge-pendiente { background: #fff3cd; color: #856404; }
        .badge-aprobado { background: #d1edff; color: #0c5460; }
        .badge-vencido { background: #f8d7da; color: #721c24; }
        .badge-rechazado { background: #f8d7da; color: #721c24; }
        
        @media (max-width: 768px) {
            .filtro-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

        <link href="css/style.css" rel="stylesheet">
    <link href="css/registrar_pago.css" rel="stylesheet">
    
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
                <li><a href="index.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="registrar_pago.php"><i class="fas fa-plus-circle"></i> Registrar Pago</a></li>
                <li><a href="pagos.php" class="active"><i class="fas fa-list"></i> Mis Pagos</a></li>
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
                <h3>Mis Pagos</h3>
                <div class="user-info">
                    <span>Bienvenido, <?php echo $_SESSION['nombre']; ?></span>
                    <span class="badge"><?php echo ucfirst($_SESSION['rol']); ?></span>
                </div>
            </div>

            <div class="content">
                <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>

                <!-- Filtros -->
                <div class="filtros">
                    <form method="GET" action="">
                        <div class="filtro-grid">
                            <div class="form-group filtro-group">
                                <label for="estado">Estado</label>
                                <select id="estado" name="estado">
                                    <option value="todos" <?php echo $filtro_estado == 'todos' ? 'selected' : ''; ?>>Todos los estados</option>
                                    <option value="pendiente" <?php echo $filtro_estado == 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
                                    <option value="aprobado" <?php echo $filtro_estado == 'aprobado' ? 'selected' : ''; ?>>Aprobado</option>
                                    <option value="vencido" <?php echo $filtro_estado == 'vencido' ? 'selected' : ''; ?>>Vencido</option>
                                    <option value="rechazado" <?php echo $filtro_estado == 'rechazado' ? 'selected' : ''; ?>>Rechazado</option>
                                </select>
                            </div>
                            
                            <div class="form-group filtro-group">
                                <label for="mes">Mes</label>
                                <input type="month" id="mes" name="mes" value="<?php echo $filtro_mes; ?>">
                            </div>
                            
                            <div class="form-group filtro-group">
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="fas fa-filter"></i> Filtrar
                                </button>
                                <a href="pagos.php" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-times"></i> Limpiar
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Lista de Pagos -->
                <div class="card">
                    <div class="card-header">
                        <h4>Lista de Pagos (<?php echo count($pagos); ?>)</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Referencia</th>
                                        <th>Monto</th>
                                        <th>Moneda</th>
                                        <th>Fecha Pago</th>
                                        <th>Fecha Venc.</th>
                                        <th>Banco Origen</th>
                                        <th>Estado</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($pagos) > 0): ?>
                                        <?php foreach ($pagos as $pago): 
                                            $fecha_pago = $pago['fecha_pago'] instanceof DateTime ? 
                                                $pago['fecha_pago']->format('d/m/Y') : 
                                                date('d/m/Y', strtotime($pago['fecha_pago']));
                                            
                                            $fecha_vencimiento = $pago['fecha_vencimiento'] instanceof DateTime ? 
                                                $pago['fecha_vencimiento']->format('d/m/Y') : 
                                                date('d/m/Y', strtotime($pago['fecha_vencimiento']));
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($pago['referencia']); ?></td>
                                            <td>
                                                <?php echo number_format($pago['monto'], 2); ?>
                                                <?php echo $pago['moneda'] == 'USD' ? ' USD' : ' BS'; ?>
                                            </td>
                                            <td><?php echo $pago['moneda']; ?></td>
                                            <td><?php echo $fecha_pago; ?></td>
                                            <td><?php echo $fecha_vencimiento; ?></td>
                                            <td><?php echo htmlspecialchars($pago['banco_origen']); ?></td>
                                            <td>
                                                <span class="badge badge-<?php echo $pago['estado']; ?>">
                                                    <?php echo ucfirst($pago['estado']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="acciones-pago">
                                                    <a href="ver_pago.php?id=<?php echo $pago['id']; ?>" 
                                                       class="btn btn-primary btn-icon" title="Ver detalles">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if ($pago['estado'] == 'pendiente'): ?>
                                                    <a href="editar_pago.php?id=<?php echo $pago['id']; ?>" 
                                                       class="btn btn-secondary btn-icon" title="Editar">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" style="text-align: center; padding: 40px;">
                                                <i class="fas fa-receipt" style="font-size: 48px; color: #bdc3c7; margin-bottom: 15px;"></i>
                                                <p>No se encontraron pagos</p>
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
</body>
</html>