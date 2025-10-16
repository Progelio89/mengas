<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] != 'admin') {
    header('Location: index.php');
    exit();
}

try {
    $db = new Database('A');
    
    // Obtener configuración actual
    $sql = "SELECT TOP 1 * FROM tasas_bcv WHERE activa = 1 ORDER BY fecha_actualizacion DESC";
    $tasa_actual = $db->fetchSingle($sql);
    
    // Obtener historial de tasas
    $sql_historial = "SELECT TOP 10 * FROM tasas_bcv ORDER BY fecha_actualizacion DESC";
    $historial_tasas = $db->fetchArray($sql_historial);
    
} catch (Exception $e) {
    $error = "Error al cargar configuración: " . $e->getMessage();
}

// Procesar actualización de tasa
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['actualizar_tasa'])) {
    try {
        $nueva_tasa = floatval($_POST['tasa_usd']);
        
        // Desactivar tasas anteriores
        $sql_desactivar = "UPDATE tasas_bcv SET activa = 0 WHERE activa = 1";
        $db->executeQuery($sql_desactivar);
        
        // Insertar nueva tasa
        $sql_insert = "INSERT INTO tasas_bcv (tasa_usd) VALUES (?)";
        $db->executeQuery($sql_insert, array($nueva_tasa));
        
        $success = "Tasa BCV actualizada exitosamente!";
        
        // Recargar datos
        $tasa_actual = $db->fetchSingle("SELECT TOP 1 * FROM tasas_bcv WHERE activa = 1 ORDER BY fecha_actualizacion DESC");
        $historial_tasas = $db->fetchArray("SELECT TOP 10 * FROM tasas_bcv ORDER BY fecha_actualizacion DESC");
        
    } catch (Exception $e) {
        $error = "Error al actualizar tasa: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración - Sistema de Pagos</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .config-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }
        
        .tasa-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            text-align: center;
        }
        
        .tasa-valor {
            font-size: 48px;
            font-weight: bold;
            margin: 20px 0;
        }
        
        .tasa-fecha {
            font-size: 14px;
            opacity: 0.8;
        }
        
        .historial-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .historial-tasa {
            font-size: 18px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .historial-fecha {
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .activa-badge {
            background: #27ae60;
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
        }
        
        @media (max-width: 768px) {
            .config-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

         <link href="css/style.css" rel="stylesheet">
         
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
                <li><a href="conciliacion.php"><i class="fas fa-exchange-alt"></i> Conciliación</a></li>
                <li><a href="usuarios.php"><i class="fas fa-users"></i> Usuarios</a></li>
                <li><a href="configuracion.php" class="active"><i class="fas fa-cog"></i> Configuración</a></li>
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="top-nav">
                <h3>Configuración del Sistema</h3>
                <div class="user-info">
                    <span>Bienvenido, <?php echo $_SESSION['nombre']; ?></span>
                    <span class="badge"><?php echo ucfirst($_SESSION['rol']); ?></span>
                </div>
            </div>

            <div class="content">
                <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if (isset($success)): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>

                <div class="config-grid">
                    <!-- Configuración de Tasa BCV -->
                    <div>
                        <div class="card">
                            <div class="card-header">
                                <h4><i class="fas fa-chart-line"></i> Tasa BCV</h4>
                            </div>
                            <div class="card-body">
                                <?php if ($tasa_actual): 
                                    $fecha_tasa = $tasa_actual['fecha_actualizacion'] instanceof DateTime ? 
                                        $tasa_actual['fecha_actualizacion']->format('d/m/Y H:i') : 
                                        date('d/m/Y H:i', strtotime($tasa_actual['fecha_actualizacion']));
                                ?>
                                <div class="tasa-card">
                                    <h4>Tasa Actual USD/Bs</h4>
                                    <div class="tasa-valor">Bs <?php echo number_format($tasa_actual['tasa_usd'], 2); ?></div>
                                    <div class="tasa-fecha">Actualizada: <?php echo $fecha_tasa; ?></div>
                                </div>
                                
                                <form method="POST" action="" style="margin-top: 20px;">
                                    <input type="hidden" name="actualizar_tasa" value="1">
                                    
                                    <div class="form-group">
                                        <label for="tasa_usd">Nueva Tasa USD/Bs *</label>
                                        <input type="number" id="tasa_usd" name="tasa_usd" 
                                               step="0.0001" min="0" required
                                               value="<?php echo $tasa_actual['tasa_usd']; ?>">
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-sync-alt"></i> Actualizar Tasa
                                    </button>
                                </form>
                                <?php else: ?>
                                <div style="text-align: center; padding: 40px;">
                                    <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: #f39c12; margin-bottom: 15px;"></i>
                                    <p>No hay tasa BCV configurada</p>
                                    <form method="POST" action="">
                                        <input type="hidden" name="actualizar_tasa" value="1">
                                        
                                        <div class="form-group">
                                            <label for="tasa_usd">Configurar Tasa USD/Bs *</label>
                                            <input type="number" id="tasa_usd" name="tasa_usd" 
                                                   step="0.0001" min="0" required
                                                   placeholder="Ej: 36.50">
                                        </div>
                                        
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-cog"></i> Configurar Tasa
                                        </button>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Historial de Tasas -->
                    <div>
                        <div class="card">
                            <div class="card-header">
                                <h4><i class="fas fa-history"></i> Historial de Tasas</h4>
                            </div>
                            <div class="card-body">
                                <?php if (count($historial_tasas) > 0): ?>
                                    <div style="max-height: 400px; overflow-y: auto;">
                                        <?php foreach ($historial_tasas as $tasa): 
                                            $fecha = $tasa['fecha_actualizacion'] instanceof DateTime ? 
                                                $tasa['fecha_actualizacion']->format('d/m/Y H:i') : 
                                                date('d/m/Y H:i', strtotime($tasa['fecha_actualizacion']));
                                        ?>
                                        <div class="historial-item">
                                            <div>
                                                <div class="historial-tasa">Bs <?php echo number_format($tasa['tasa_usd'], 2); ?></div>
                                                <div class="historial-fecha"><?php echo $fecha; ?></div>
                                            </div>
                                            <div>
                                                <?php if ($tasa['activa']): ?>
                                                <span class="activa-badge">Activa</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div style="text-align: center; padding: 40px;">
                                        <i class="fas fa-history" style="font-size: 48px; color: #bdc3c7; margin-bottom: 15px;"></i>
                                        <p>No hay historial de tasas</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Información del Sistema -->
                        <div class="card" style="margin-top: 20px;">
                            <div class="card-header">
                                <h4><i class="fas fa-info-circle"></i> Información del Sistema</h4>
                            </div>
                            <div class="card-body">
                                <div class="info-group">
                                    <div class="info-label">Versión</div>
                                    <div class="info-value">1.0.0</div>
                                </div>
                                <div class="info-group">
                                    <div class="info-label">Base de Datos</div>
                                    <div class="info-value"><?php echo $db->getDatabaseName(); ?></div>
                                </div>
                                <div class="info-group">
                                    <div class="info-label">Servidor</div>
                                    <div class="info-value"><?php echo $db->getCurrentServer(); ?></div>
                                </div>
                                <div class="info-group">
                                    <div class="info-label">Usuarios Registrados</div>
                                    <div class="info-value">
                                        <?php 
                                            $sql_usuarios = "SELECT COUNT(*) as total FROM usuarios";
                                            $total_usuarios = $db->fetchSingle($sql_usuarios);
                                            echo $total_usuarios['total'];
                                        ?>
                                    </div>
                                </div>
                                <div class="info-group">
                                    <div class="info-label">Pagos Registrados</div>
                                    <div class="info-value">
                                        <?php 
                                            $sql_pagos = "SELECT COUNT(*) as total FROM pagos";
                                            $total_pagos = $db->fetchSingle($sql_pagos);
                                            echo $total_pagos['total'];
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>