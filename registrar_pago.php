<?php
$page_title = "Registrar Nuevo Pago";
require_once 'header.php';

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
        $banco_origen = $_POST['banco_origen'];
        $banco_destino = $_POST['banco_destino'];
        $cedula_titular = $_POST['cedula_titular'];
        $telefono_titular = $_POST['telefono_titular'];
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
                                  fecha_pago, banco_origen, banco_destino, 
                                  cedula_titular, telefono_titular, observaciones, captura_url, estado) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente')";
        
        $params = array(
            $_SESSION['usuario_id'], $monto, $moneda, $tasa_usd, $monto_bs, $referencia,
            $fecha_pago, $banco_origen, $banco_destino,
            $cedula_titular, $telefono_titular, $observaciones, $captura_url
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

                            <div class="form-group">
                                <label for="cedula_titular">Cédula del Titular *</label>
                                <input type="text" id="cedula_titular" name="cedula_titular" 
                                       value="<?php echo $_POST['cedula_titular'] ?? ''; ?>" 
                                       placeholder="Ej: V12345678" required>
                            </div>

                            <div class="form-group">
                                <label for="telefono_titular">Teléfono del Titular *</label>
                                <input type="tel" id="telefono_titular" name="telefono_titular" 
                                       value="<?php echo $_POST['telefono_titular'] ?? ''; ?>" 
                                       placeholder="Ej: 04121234567" required>
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

        // Validar formato de cédula
        document.getElementById('cedula_titular').addEventListener('blur', function() {
            const cedula = this.value.trim();
            if (cedula && !/^[VEJPGvejpg]\d{5,9}$/.test(cedula)) {
                alert('Formato de cédula inválido. Ejemplos válidos: V12345678, E87654321');
                this.focus();
            }
        });

        // Validar formato de teléfono
        document.getElementById('telefono_titular').addEventListener('blur', function() {
            const telefono = this.value.trim();
            if (telefono && !/^04(14|12|16|24|26)\d{7}$/.test(telefono)) {
                alert('Formato de teléfono inválido. Debe comenzar con 04 seguido del código de operador (14,12,16,24,26) y 7 dígitos más.');
                this.focus();
            }
        });

        // Cargar tasa al iniciar
        cargarTasaBCV();
    </script>


<?php require_once 'footer.php'; ?>