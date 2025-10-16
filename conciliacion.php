<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] != 'admin' && $_SESSION['rol'] != 'consolidador')) {
    header('Location: index.php');
    exit();
}

$filtro_estado = $_GET['estado'] ?? 'pendiente';
$filtro_fecha = $_GET['fecha'] ?? '';

try {
    $db = new Database('A');
    
    // Construir consulta para pagos pendientes de conciliación
    $sql = "SELECT p.*, u.nombre as usuario_nombre, c.estado_conciliacion, c.referencia_banco
            FROM pagos p 
            JOIN usuarios u ON p.usuario_id = u.id 
            LEFT JOIN conciliacion c ON p.id = c.pago_id
            WHERE p.estado = 'aprobado'";
    
    $params = array();
    
    if ($filtro_estado != 'todos') {
        if ($filtro_estado == 'pendiente') {
            $sql .= " AND (c.estado_conciliacion IS NULL OR c.estado_conciliacion = 'pendiente')";
        } else {
            $sql .= " AND c.estado_conciliacion = ?";
            $params[] = $filtro_estado;
        }
    }
    
    if ($filtro_fecha) {
        $sql .= " AND p.fecha_pago >= ?";
        $params[] = $filtro_fecha;
    }
    
    $sql .= " ORDER BY p.fecha_pago DESC";
    
    $pagos = $db->fetchArray($sql, $params);
    
    // Bancos para el formulario
    $bancos = ['Banesco', 'Mercantil', 'Provincial', 'Venezuela', 'Bancaribe', 'BNC', 'Otro'];
    
} catch (Exception $e) {
    $error = "Error al cargar datos: " . $e->getMessage();
}

// Procesar conciliación
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['conciliar'])) {
    try {
        $pago_id = $_POST['pago_id'];
        $referencia_banco = $_POST['referencia_banco'];
        $monto_banco = floatval($_POST['monto_banco']);
        $fecha_banco = $_POST['fecha_banco'];
        $estado_conciliacion = $_POST['estado_conciliacion'];
        $observaciones = $_POST['observaciones'];
        
        // Verificar si ya existe conciliación
        $sql_check = "SELECT id FROM conciliacion WHERE pago_id = ?";
        $existe = $db->fetchSingle($sql_check, array($pago_id));
        
        if ($existe) {
            // Actualizar
            $sql = "UPDATE conciliacion SET referencia_banco = ?, monto_banco = ?, fecha_banco = ?, 
                    estado_conciliacion = ?, observaciones = ?, fecha_conciliacion = GETDATE() 
                    WHERE pago_id = ?";
            $params = array($referencia_banco, $monto_banco, $fecha_banco, $estado_conciliacion, $observaciones, $pago_id);
        } else {
            // Insertar
            $sql = "INSERT INTO conciliacion (pago_id, referencia_banco, monto_banco, fecha_banco, 
                    estado_conciliacion, observaciones) 
                    VALUES (?, ?, ?, ?, ?, ?)";
            $params = array($pago_id, $referencia_banco, $monto_banco, $fecha_banco, $estado_conciliacion, $observaciones);
        }
        
        $stmt = $db->executeQuery($sql, $params);
        
        $success = "Conciliación procesada exitosamente!";
        
    } catch (Exception $e) {
        $error = "Error al procesar conciliación: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conciliación - Sistema de Pagos</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .conciliacion-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .comparacion-item {
            display: flex;
            justify-content: between;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .comparacion-label {
            font-weight: 600;
            color: #555;
            min-width: 120px;
        }
        
        .comparacion-valor {
            flex: 1;
        }
        
        .match {
            color: #27ae60;
        }
        
        .mismatch {
            color: #e74c3c;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            padding: 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            border-radius: 8px 8px 0 0;
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .close {
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            color: #6c757d;
        }
        
        .close:hover {
            color: #343a40;
        }
        
        @media (max-width: 768px) {
            .conciliacion-grid {
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
                <li><a href="conciliacion.php" class="active"><i class="fas fa-exchange-alt"></i> Conciliación</a></li>
                <li><a href="usuarios.php"><i class="fas fa-users"></i> Usuarios</a></li>
                <?php if ($_SESSION['rol'] == 'admin'): ?>
                <li><a href="configuracion.php"><i class="fas fa-cog"></i> Configuración</a></li>
                <?php endif; ?>
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="top-nav">
                <h3>Conciliación Bancaria</h3>
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

                <!-- Filtros -->
                <div class="filtros">
                    <form method="GET" action="">
                        <div class="filtro-grid">
                            <div class="form-group filtro-group">
                                <label for="estado">Estado Conciliación</label>
                                <select id="estado" name="estado">
                                    <option value="pendiente" <?php echo $filtro_estado == 'pendiente' ? 'selected' : ''; ?>>Pendientes</option>
                                    <option value="conciliado" <?php echo $filtro_estado == 'conciliado' ? 'selected' : ''; ?>>Conciliados</option>
                                    <option value="discrepancia" <?php echo $filtro_estado == 'discrepancia' ? 'selected' : ''; ?>>Discrepancias</option>
                                    <option value="todos" <?php echo $filtro_estado == 'todos' ? 'selected' : ''; ?>>Todos</option>
                                </select>
                            </div>
                            
                            <div class="form-group filtro-group">
                                <label for="fecha">Desde Fecha</label>
                                <input type="date" id="fecha" name="fecha" value="<?php echo $filtro_fecha; ?>">
                            </div>
                            
                            <div class="form-group filtro-group">
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="fas fa-filter"></i> Filtrar
                                </button>
                                <a href="conciliacion.php" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-times"></i> Limpiar
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Lista de Pagos para Conciliar -->
                <div class="card">
                    <div class="card-header">
                        <h4>Pagos para Conciliar (<?php echo count($pagos); ?>)</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Referencia</th>
                                        <th>Usuario</th>
                                        <th>Monto</th>
                                        <th>Fecha Pago</th>
                                        <th>Banco Origen</th>
                                        <th>Estado Conciliación</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($pagos) > 0): ?>
                                        <?php foreach ($pagos as $pago): 
                                            $fecha_pago = $pago['fecha_pago'] instanceof DateTime ? 
                                                $pago['fecha_pago']->format('d/m/Y') : 
                                                date('d/m/Y', strtotime($pago['fecha_pago']));
                                            
                                            $estado_conciliacion = $pago['estado_conciliacion'] ?: 'pendiente';
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($pago['referencia']); ?></td>
                                            <td><?php echo htmlspecialchars($pago['usuario_nombre']); ?></td>
                                            <td>
                                                <?php echo number_format($pago['monto'], 2); ?>
                                                <?php echo $pago['moneda']; ?>
                                            </td>
                                            <td><?php echo $fecha_pago; ?></td>
                                            <td><?php echo htmlspecialchars($pago['banco_origen']); ?></td>
                                            <td>
                                                <span class="badge badge-<?php echo $estado_conciliacion; ?>">
                                                    <?php echo ucfirst($estado_conciliacion); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="acciones-pago">
                                                    <button type="button" class="btn btn-primary btn-icon" 
                                                            onclick="abrirModalConciliacion(<?php echo htmlspecialchars(json_encode($pago)); ?>)"
                                                            title="Conciliar">
                                                        <i class="fas fa-exchange-alt"></i>
                                                    </button>
                                                    <a href="ver_pago.php?id=<?php echo $pago['id']; ?>" 
                                                       class="btn btn-secondary btn-icon" title="Ver detalles">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" style="text-align: center; padding: 40px;">
                                                <i class="fas fa-check-circle" style="font-size: 48px; color: #bdc3c7; margin-bottom: 15px;"></i>
                                                <p>No hay pagos pendientes de conciliación</p>
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

    <!-- Modal Conciliación -->
    <div id="modalConciliacion" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h4><i class="fas fa-exchange-alt"></i> Procesar Conciliación</h4>
                <span class="close" onclick="cerrarModalConciliacion()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="formConciliacion" method="POST" action="">
                    <input type="hidden" name="pago_id" id="pago_id">
                    <input type="hidden" name="conciliar" value="1">
                    
                    <div class="conciliacion-grid">
                        <!-- Datos del Sistema -->
                        <div>
                            <h5>Datos del Sistema</h5>
                            <div class="info-card">
                                <div class="comparacion-item">
                                    <div class="comparacion-label">Referencia:</div>
                                    <div class="comparacion-valor" id="modal_referencia"></div>
                                </div>
                                <div class="comparacion-item">
                                    <div class="comparacion-label">Monto:</div>
                                    <div class="comparacion-valor" id="modal_monto"></div>
                                </div>
                                <div class="comparacion-item">
                                    <div class="comparacion-label">Fecha Pago:</div>
                                    <div class="comparacion-valor" id="modal_fecha_pago"></div>
                                </div>
                                <div class="comparacion-item">
                                    <div class="comparacion-label">Banco Origen:</div>
                                    <div class="comparacion-valor" id="modal_banco_origen"></div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Datos Bancarios -->
                        <div>
                            <h5>Datos del Banco</h5>
                            <div class="form-group">
                                <label for="referencia_banco">Referencia Bancaria *</label>
                                <input type="text" id="referencia_banco" name="referencia_banco" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="monto_banco">Monto Bancario *</label>
                                <input type="number" id="monto_banco" name="monto_banco" step="0.01" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="fecha_banco">Fecha Bancaria *</label>
                                <input type="date" id="fecha_banco" name="fecha_banco" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="estado_conciliacion">Estado *</label>
                                <select id="estado_conciliacion" name="estado_conciliacion" required>
                                    <option value="conciliado">Conciliado</option>
                                    <option value="discrepancia">Discrepancia</option>
                                    <option value="pendiente">Pendiente</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="observaciones">Observaciones</label>
                                <textarea id="observaciones" name="observaciones" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div style="margin-top: 20px; display: flex; gap: 10px;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Guardar Conciliación
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="cerrarModalConciliacion()">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function abrirModalConciliacion(pago) {
            document.getElementById('pago_id').value = pago.id;
            document.getElementById('modal_referencia').textContent = pago.referencia;
            document.getElementById('modal_monto').textContent = pago.monto + ' ' + pago.moneda;
            
            // Formatear fecha
            let fechaPago = new Date(pago.fecha_pago);
            document.getElementById('modal_fecha_pago').textContent = fechaPago.toLocaleDateString('es-ES');
            
            document.getElementById('modal_banco_origen').textContent = pago.banco_origen;
            
            // Llenar datos existentes si los hay
            if (pago.referencia_banco) {
                document.getElementById('referencia_banco').value = pago.referencia_banco;
            }
            if (pago.estado_conciliacion) {
                document.getElementById('estado_conciliacion').value = pago.estado_conciliacion;
            }
            
            // Establecer fecha actual por defecto
            document.getElementById('fecha_banco').valueAsDate = new Date();
            
            document.getElementById('modalConciliacion').style.display = 'block';
        }
        
        function cerrarModalConciliacion() {
            document.getElementById('modalConciliacion').style.display = 'none';
        }
        
        // Cerrar modal al hacer clic fuera
        window.onclick = function(event) {
            const modal = document.getElementById('modalConciliacion');
            if (event.target == modal) {
                cerrarModalConciliacion();
            }
        }
        
        // Validar monto bancario vs monto sistema
        document.getElementById('monto_banco').addEventListener('change', function() {
            const montoSistema = parseFloat(document.getElementById('modal_monto').textContent.split(' ')[0]);
            const montoBanco = parseFloat(this.value);
            
            if (montoSistema && montoBanco) {
                const estadoSelect = document.getElementById('estado_conciliacion');
                if (Math.abs(montoSistema - montoBanco) > 0.01) {
                    estadoSelect.value = 'discrepancia';
                } else {
                    estadoSelect.value = 'conciliado';
                }
            }
        });
    </script>
</body>
</html>