<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $db = new Database('A');
        $conn = $db->getConnection();
        
        // Obtener tasa BCV actual
        $tasa_sql = "SELECT TOP 1 tasa_usd FROM tasas_bcv WHERE activa = 1 ORDER BY fecha_actualizacion DESC";
        $tasa_stmt = sqlsrv_query($conn, $tasa_sql);
        $tasa_row = sqlsrv_fetch_array($tasa_stmt, SQLSRV_FETCH_ASSOC);
        $tasa_usd = $tasa_row ? $tasa_row['tasa_usd'] : 0;
        sqlsrv_free_stmt($tasa_stmt);
        
        // Procesar datos del formulario
        $monto = floatval($_POST['monto']);
        $moneda = $_POST['moneda'];
        $referencia = $_POST['referencia'];
        $fecha_pago = $_POST['fecha_pago'];
        $fecha_vencimiento = $_POST['fecha_vencimiento'];
        $banco_origen = $_POST['banco_origen'];
        $banco_destino = $_POST['banco_destino'];
        $observaciones = $_POST['observaciones'];
        
        // Calcular monto en BS si es USD
        $monto_bs = ($moneda == 'USD') ? $monto * $tasa_usd : $monto;
        
        // Procesar imagen de captura
        $captura_url = '';
        if (isset($_FILES['captura']) && $_FILES['captura']['error'] == 0) {
            $upload_dir = 'uploads/capturas/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = pathinfo($_FILES['captura']['name'], PATHINFO_EXTENSION);
            $filename = uniqid() . '_' . time() . '.' . $file_extension;
            $filepath = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES['captura']['tmp_name'], $filepath)) {
                $captura_url = $filepath;
            }
        }
        
        // Insertar pago
        $sql = "INSERT INTO pagos (usuario_id, monto, moneda, tasa_aplicada, monto_bs, referencia, 
                                  fecha_pago, fecha_vencimiento, banco_origen, banco_destino, 
                                  observaciones, captura_url, estado) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente')";
        
        $params = array(
            $_SESSION['usuario_id'], $monto, $moneda, $tasa_usd, $monto_bs, $referencia,
            $fecha_pago, $fecha_vencimiento, $banco_origen, $banco_destino,
            $observaciones, $captura_url
        );
        
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt) {
            $success = "Pago registrado exitosamente!";
            $_POST = array(); // Limpiar formulario
        } else {
            $error = "Error al registrar pago: " . print_r(sqlsrv_errors(), true);
        }
        
        if ($stmt) sqlsrv_free_stmt($stmt);
        
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrar Pago - Sistema de Pagos</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/registrar_pago.css" rel="stylesheet">
  
</head>
<body>
    <div class="container">
        <!-- Sidebar (igual al index.php) -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-money-bill-wave"></i> Sistema Pagos</h2>
                <small>Base: sistema_pagos</small>
            </div>
            <ul class="sidebar-menu">
                <li><a href="index.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="registrar_pago.php" class="active"><i class="fas fa-plus-circle"></i> Registrar Pago</a></li>
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
                <h3>Registrar Nuevo Pago</h3>
                <div class="user-info">
                    <span>Bienvenido, <?php echo $_SESSION['nombre']; ?></span>
                    <span class="badge"><?php echo ucfirst($_SESSION['rol']); ?></span>
                </div>
            </div>

            <div class="content">
                <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>

                <div class="form-container">
                    <div class="tasa-info">
                        <strong>Tasa BCV Actual:</strong> 
                        <span id="tasa-actual">Cargando...</span>
                    </div>

                    <form method="POST" action="" enctype="multipart/form-data">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="monto">Monto *</label>
                                <input type="number" id="monto" name="monto" step="0.01" min="0" 
                                       value="<?php echo $_POST['monto'] ?? ''; ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="moneda">Moneda *</label>
                                <select id="moneda" name="moneda" required>
                                    <option value="USD" <?php echo ($_POST['moneda'] ?? '') == 'USD' ? 'selected' : ''; ?>>USD</option>
                                    <option value="BS" <?php echo ($_POST['moneda'] ?? '') == 'BS' ? 'selected' : ''; ?>>Bolívares</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="referencia">Número de Referencia *</label>
                                <input type="text" id="referencia" name="referencia" 
                                       value="<?php echo $_POST['referencia'] ?? ''; ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="fecha_pago">Fecha de Pago *</label>
                                <input type="date" id="fecha_pago" name="fecha_pago" 
                                       value="<?php echo $_POST['fecha_pago'] ?? date('Y-m-d'); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="fecha_vencimiento">Fecha de Vencimiento *</label>
                                <input type="date" id="fecha_vencimiento" name="fecha_vencimiento" 
                                       value="<?php echo $_POST['fecha_vencimiento'] ?? date('Y-m-d', strtotime('+30 days')); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="banco_origen">Banco Origen *</label>
                                <select id="banco_origen" name="banco_origen" required>
                                    <option value="">Seleccionar banco</option>
                                    <option value="Banesco">Banesco</option>
                                    <option value="Mercantil">Mercantil</option>
                                    <option value="Provincial">Provincial</option>
                                    <option value="Venezuela">Banco de Venezuela</option>
                                    <option value="Bancaribe">Bancaribe</option>
                                    <option value="BNC">BNC</option>
                                    <option value="Otro">Otro</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="banco_destino">Banco Destino *</label>
                                <select id="banco_destino" name="banco_destino" required>
                                    <option value="">Seleccionar banco</option>
                                    <option value="Banesco">Banesco</option>
                                    <option value="Mercantil">Mercantil</option>
                                    <option value="Provincial">Provincial</option>
                                    <option value="Venezuela">Banco de Venezuela</option>
                                    <option value="Bancaribe">Bancaribe</option>
                                    <option value="BNC">BNC</option>
                                    <option value="Otro">Otro</option>
                                </select>
                            </div>

                            <div class="form-group form-full">
                                <label for="captura">Captura del Pago (Imagen)</label>
                                <div class="file-upload" onclick="document.getElementById('captura').click()">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <p>Haz clic para subir la captura del pago</p>
                                    <small>Formatos: JPG, PNG, PDF (Máx. 5MB)</small>
                                </div>
                                <input type="file" id="captura" name="captura" accept="image/*,.pdf" 
                                       style="display: none;" onchange="updateFileName(this)">
                                <div id="file-name" style="margin-top: 10px; font-size: 12px; color: #666;"></div>
                            </div>

                            <div class="form-group form-full">
                                <label for="observaciones">Observaciones</label>
                                <textarea id="observaciones" name="observaciones" rows="4" 
                                          placeholder="Observaciones adicionales..."><?php echo $_POST['observaciones'] ?? ''; ?></textarea>
                            </div>
                        </div>

                        <div style="display: flex; gap: 10px; margin-top: 30px;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Registrar Pago
                            </button>
                            <a href="index.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Volver al Dashboard
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Cargar tasa BCV
        function cargarTasaBCV() {
            fetch('api/tasa_bcv.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('tasa-actual').textContent = 'Bs ' + data.tasa.toFixed(2);
                    } else {
                        document.getElementById('tasa-actual').textContent = 'No disponible';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('tasa-actual').textContent = 'Error al cargar';
                });
        }

        // Actualizar nombre de archivo
        function updateFileName(input) {
            const fileNameDiv = document.getElementById('file-name');
            if (input.files.length > 0) {
                fileNameDiv.innerHTML = '<i class="fas fa-file"></i> Archivo seleccionado: ' + input.files[0].name;
            } else {
                fileNameDiv.innerHTML = '';
            }
        }

        // Validar fechas
        document.getElementById('fecha_pago').addEventListener('change', function() {
            const fechaVencimiento = document.getElementById('fecha_vencimiento');
            if (!fechaVencimiento.value) {
                fechaVencimiento.min = this.value;
            }
        });

        // Cargar tasa al iniciar
        cargarTasaBCV();
    </script>
</body>
</html>