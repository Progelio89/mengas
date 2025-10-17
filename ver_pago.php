<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

$pago_id = $_GET['id'] ?? 0;

try {
    $db = new Database('A');
    
    // Verificar permisos
    $sql = "SELECT p.*, u.nombre as usuario_nombre 
            FROM pagos p 
            JOIN usuarios u ON p.usuario_id = u.id 
            WHERE p.id = ?";
    
    if ($_SESSION['rol'] != 'admin' && $_SESSION['rol'] != 'consolidador') {
        $sql .= " AND p.usuario_id = ?";
        $pago = $db->fetchSingle($sql, array($pago_id, $_SESSION['usuario_id']));
    } else {
        $pago = $db->fetchSingle($sql, array($pago_id));
    }
    
    if (!$pago) {
        header('Location: pagos.php');
        exit();
    }
    
} catch (Exception $e) {
    $error = "Error al cargar pago: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalles del Pago - Sistema de Pagos</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .detalles-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
        }
        
        .info-card {
            background: white;
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .info-group {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .info-label {
            font-weight: 600;
            color: #555;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 16px;
            color: #333;
        }
        
        .captura-container {
            text-align: center;
            margin-top: 20px;
        }
        
        .captura-img {
            max-width: 100%;
            max-height: 400px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        
        .estado-badge {
            display: inline-block;
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 14px;
        }
        
        .estado-pendiente { background: #fff3cd; color: #856404; }
        .estado-aprobado { background: #d1edff; color: #0c5460; }
        .estado-vencido { background: #f8d7da; color: #721c24; }
        
        @media (max-width: 768px) {
            .detalles-grid {
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
                <h3>Detalles del Pago</h3>
                <div class="user-info">
                    <span>Bienvenido, <?php echo $_SESSION['nombre']; ?></span>
                    <span class="badge"><?php echo ucfirst($_SESSION['rol']); ?></span>
                </div>
            </div>

            <div class="content">
                <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>

                <div style="display: flex; gap: 10px; margin-bottom: 20px;">
                    <a href="pagos.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Volver a Mis Pagos
                    </a>
                    <?php if ($pago['estado'] == 'pendiente' && ($_SESSION['rol'] == 'admin' || $_SESSION['usuario_id'] == $pago['usuario_id'])): ?>
                    <a href="editar_pago.php?id=<?php echo $pago['id']; ?>" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Editar Pago
                    </a>
                    <?php endif; ?>
                </div>

                <div class="detalles-grid">
                    <!-- Información Principal -->
                    <div class="info-card">
                        <h4 style="margin-bottom: 25px; color: #2c3e50;">
                            <i class="fas fa-file-invoice-dollar"></i> Información del Pago
                        </h4>
                        
                        <div class="info-group">
                            <div class="info-label">Referencia</div>
                            <div class="info-value"><?php echo htmlspecialchars($pago['referencia']); ?></div>
                        </div>
                        
                        <div class="info-group">
                            <div class="info-label">Estado</div>
                            <div class="info-value">
                                <span class="estado-badge estado-<?php echo $pago['estado']; ?>">
                                    <?php echo ucfirst($pago['estado']); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div class="info-group">
                                <div class="info-label">Monto</div>
                                <div class="info-value">
                                    <?php echo number_format($pago['monto'], 2); ?> <?php echo $pago['moneda']; ?>
                                </div>
                            </div>
                            
                            <div class="info-group">
                                <div class="info-label">Monto en Bs</div>
                                <div class="info-value">
                                    Bs <?php echo number_format($pago['monto_bs'], 2); ?>
                                </div>
                            </div>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div class="info-group">
                                <div class="info-label">Fecha de Pago</div>
                                <div class="info-value">
                                    <?php 
                                        $fecha_pago = $pago['fecha_pago'] instanceof DateTime ? 
                                            $pago['fecha_pago']->format('d/m/Y') : 
                                            date('d/m/Y', strtotime($pago['fecha_pago']));
                                        echo $fecha_pago;
                                    ?>
                                </div>
                            </div>
                            
                           
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div class="info-group">
                                <div class="info-label">Banco Origen</div>
                                <div class="info-value"><?php echo htmlspecialchars($pago['banco_origen']); ?></div>
                            </div>
                            
                            <div class="info-group">
                                <div class="info-label">Banco Destino</div>
                                <div class="info-value"><?php echo htmlspecialchars($pago['banco_destino']); ?></div>
                            </div>
                        </div>
                        
                        <?php if ($pago['observaciones']): ?>
                        <div class="info-group">
                            <div class="info-label">Observaciones</div>
                            <div class="info-value"><?php echo nl2br(htmlspecialchars($pago['observaciones'])); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="info-group">
                            <div class="info-label">Fecha de Registro</div>
                            <div class="info-value">
                                <?php 
                                    $fecha_registro = $pago['fecha_registro'] instanceof DateTime ? 
                                        $pago['fecha_registro']->format('d/m/Y H:i') : 
                                        date('d/m/Y H:i', strtotime($pago['fecha_registro']));
                                    echo $fecha_registro;
                                ?>
                            </div>
                        </div>
                        
                        <?php if ($_SESSION['rol'] == 'admin' || $_SESSION['rol'] == 'consolidador'): ?>
                        <div class="info-group">
                            <div class="info-label">Registrado por</div>
                            <div class="info-value"><?php echo htmlspecialchars($pago['usuario_nombre']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Captura y Acciones -->
                    <div>
                        <!-- Captura del Pago -->
                        <?php if ($pago['captura_url']): ?>
                        <div class="info-card">
                            <h4 style="margin-bottom: 15px; color: #2c3e50;">
                                <i class="fas fa-camera"></i> Captura del Pago
                            </h4>
                            <div class="captura-container">
                                <?php if (strpos($pago['captura_url'], '.pdf') !== false): ?>
                                    <div style="padding: 20px; background: #f8f9fa; border-radius: 5px;">
                                        <i class="fas fa-file-pdf" style="font-size: 48px; color: #e74c3c;"></i>
                                        <p style="margin: 10px 0;">Documento PDF</p>
                                        <a href="<?php echo $pago['captura_url']; ?>" target="_blank" 
                                           class="btn btn-primary">
                                            <i class="fas fa-download"></i> Descargar PDF
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <img src="<?php echo $pago['captura_url']; ?>" 
                                         alt="Captura del pago" 
                                         class="captura-img"
                                         onclick="window.open('<?php echo $pago['captura_url']; ?>', '_blank')"
                                         style="cursor: pointer;">
                                    <div style="margin-top: 10px;">
                                        <a href="<?php echo $pago['captura_url']; ?>" target="_blank" 
                                           class="btn btn-primary btn-sm">
                                            <i class="fas fa-expand"></i> Ver en tamaño completo
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Acciones (para administradores/consolidadores) -->
                        <?php if (($_SESSION['rol'] == 'admin' || $_SESSION['rol'] == 'consolidador') && $pago['estado'] == 'pendiente'): ?>
                        <div class="info-card">
                            <h4 style="margin-bottom: 15px; color: #2c3e50;">
                                <i class="fas fa-cog"></i> Acciones
                            </h4>
                            <div style="display: flex; flex-direction: column; gap: 10px;">
                                <form action="procesar_pago.php" method="POST" style="margin: 0;">
                                    <input type="hidden" name="pago_id" value="<?php echo $pago['id']; ?>">
                                    <input type="hidden" name="accion" value="aprobar">
                                    <button type="submit" class="btn btn-success" style="width: 100%;">
                                        <i class="fas fa-check"></i> Aprobar Pago
                                    </button>
                                </form>
                                
                                <form action="procesar_pago.php" method="POST" style="margin: 0;">
                                    <input type="hidden" name="pago_id" value="<?php echo $pago['id']; ?>">
                                    <input type="hidden" name="accion" value="rechazar">
                                    <button type="submit" class="btn btn-danger" style="width: 100%;">
                                        <i class="fas fa-times"></i> Rechazar Pago
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>