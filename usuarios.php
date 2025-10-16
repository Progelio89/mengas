<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['usuario_id']) || ($_SESSION['rol'] != 'admin' && $_SESSION['rol'] != 'consolidador')) {
    header('Location: index.php');
    exit();
}

try {
    $db = new Database('A');
    
    // Obtener lista de usuarios
    $sql = "SELECT * FROM usuarios ORDER BY fecha_creacion DESC";
    $usuarios = $db->fetchArray($sql);
    
} catch (Exception $e) {
    $error = "Error al cargar usuarios: " . $e->getMessage();
}

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $accion = $_POST['accion'];
        
        if ($accion == 'crear') {
            // Crear nuevo usuario
            $nombre = $_POST['nombre'];
            $email = $_POST['email'];
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $rol = $_POST['rol'];
            $telefono = $_POST['telefono'];
            
            $sql = "INSERT INTO usuarios (nombre, email, password, rol, telefono) VALUES (?, ?, ?, ?, ?)";
            $db->executeQuery($sql, array($nombre, $email, $password, $rol, $telefono));
            
            $success = "Usuario creado exitosamente!";
            
        } elseif ($accion == 'editar') {
            // Editar usuario
            $id = $_POST['id'];
            $nombre = $_POST['nombre'];
            $email = $_POST['email'];
            $rol = $_POST['rol'];
            $telefono = $_POST['telefono'];
            $activo = $_POST['activo'] ?? 0;
            
            $sql = "UPDATE usuarios SET nombre = ?, email = ?, rol = ?, telefono = ?, activo = ? WHERE id = ?";
            $db->executeQuery($sql, array($nombre, $email, $rol, $telefono, $activo, $id));
            
            $success = "Usuario actualizado exitosamente!";
            
        } elseif ($accion == 'cambiar_password') {
            // Cambiar contraseña
            $id = $_POST['id'];
            $password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            
            $sql = "UPDATE usuarios SET password = ? WHERE id = ?";
            $db->executeQuery($sql, array($password, $id));
            
            $success = "Contraseña actualizada exitosamente!";
        }
        
        // Recargar usuarios
        $usuarios = $db->fetchArray("SELECT * FROM usuarios ORDER BY fecha_creacion DESC");
        
    } catch (Exception $e) {
        $error = "Error al procesar usuario: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios - Sistema de Pagos</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .usuarios-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: #27ae60;
        }
        
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        
        .badge-admin { background: #e74c3c; color: white; }
        .badge-consolidador { background: #3498db; color: white; }
        .badge-cliente { background: #27ae60; color: white; }
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
                <li><a href="usuarios.php" class="active"><i class="fas fa-users"></i> Usuarios</a></li>
                <?php if ($_SESSION['rol'] == 'admin'): ?>
                <li><a href="configuracion.php"><i class="fas fa-cog"></i> Configuración</a></li>
                <?php endif; ?>
                <li><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a></li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="top-nav">
                <h3>Gestión de Usuarios</h3>
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

                <div class="usuarios-header">
                    <h4>Lista de Usuarios (<?php echo count($usuarios); ?>)</h4>
                    <?php if ($_SESSION['rol'] == 'admin'): ?>
                    <button type="button" class="btn btn-primary" onclick="abrirModalUsuario('crear')">
                        <i class="fas fa-plus"></i> Nuevo Usuario
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Lista de Usuarios -->
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Nombre</th>
                                        <th>Email</th>
                                        <th>Teléfono</th>
                                        <th>Rol</th>
                                        <th>Estado</th>
                                        <th>Fecha Registro</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($usuarios) > 0): ?>
                                        <?php foreach ($usuarios as $usuario): 
                                            $fecha_registro = $usuario['fecha_creacion'] instanceof DateTime ? 
                                                $usuario['fecha_creacion']->format('d/m/Y') : 
                                                date('d/m/Y', strtotime($usuario['fecha_creacion']));
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($usuario['nombre']); ?></td>
                                            <td><?php echo htmlspecialchars($usuario['email']); ?></td>
                                            <td><?php echo htmlspecialchars($usuario['telefono']); ?></td>
                                            <td>
                                                <span class="badge badge-<?php echo $usuario['rol']; ?>">
                                                    <?php echo ucfirst($usuario['rol']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($_SESSION['rol'] == 'admin' && $usuario['id'] != $_SESSION['usuario_id']): ?>
                                                <label class="switch">
                                                    <input type="checkbox" <?php echo $usuario['activo'] ? 'checked' : ''; ?> 
                                                           onchange="cambiarEstadoUsuario(<?php echo $usuario['id']; ?>, this.checked)">
                                                    <span class="slider"></span>
                                                </label>
                                                <?php else: ?>
                                                    <span class="badge <?php echo $usuario['activo'] ? 'badge-success' : 'badge-danger'; ?>">
                                                        <?php echo $usuario['activo'] ? 'Activo' : 'Inactivo'; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $fecha_registro; ?></td>
                                            <td>
                                                <div class="acciones-pago">
                                                    <?php if ($_SESSION['rol'] == 'admin'): ?>
                                                    <button type="button" class="btn btn-primary btn-icon" 
                                                            onclick="abrirModalUsuario('editar', <?php echo htmlspecialchars(json_encode($usuario)); ?>)"
                                                            title="Editar">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-secondary btn-icon" 
                                                            onclick="abrirModalPassword(<?php echo $usuario['id']; ?>)"
                                                            title="Cambiar Contraseña">
                                                        <i class="fas fa-key"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" style="text-align: center; padding: 40px;">
                                                <i class="fas fa-users" style="font-size: 48px; color: #bdc3c7; margin-bottom: 15px;"></i>
                                                <p>No hay usuarios registrados</p>
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

    <!-- Modal Usuario -->
    <div id="modalUsuario" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h4 id="modalUsuarioTitulo"><i class="fas fa-user"></i> Usuario</h4>
                <span class="close" onclick="cerrarModalUsuario()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="formUsuario" method="POST" action="">
                    <input type="hidden" name="accion" id="accion">
                    <input type="hidden" name="id" id="usuario_id">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="nombre">Nombre Completo *</label>
                            <input type="text" id="nombre" name="nombre" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">Email *</label>
                            <input type="email" id="email" name="email" required>
                        </div>
                        
                        <div class="form-group" id="password-field">
                            <label for="password">Contraseña *</label>
                            <input type="password" id="password" name="password" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="rol">Rol *</label>
                            <select id="rol" name="rol" required>
                                <option value="cliente">Cliente</option>
                                <option value="consolidador">Consolidador</option>
                                <option value="admin">Administrador</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="telefono">Teléfono</label>
                            <input type="text" id="telefono" name="telefono">
                        </div>
                        
                        <div class="form-group" id="activo-field" style="display: none;">
                            <label>
                                <input type="checkbox" id="activo" name="activo" value="1"> Usuario Activo
                            </label>
                        </div>
                    </div>
                    
                    <div style="margin-top: 20px; display: flex; gap: 10px;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Guardar
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="cerrarModalUsuario()">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Cambiar Password -->
    <div id="modalPassword" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h4><i class="fas fa-key"></i> Cambiar Contraseña</h4>
                <span class="close" onclick="cerrarModalPassword()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="formPassword" method="POST" action="">
                    <input type="hidden" name="accion" value="cambiar_password">
                    <input type="hidden" name="id" id="password_usuario_id">
                    
                    <div class="form-group">
                        <label for="new_password">Nueva Contraseña *</label>
                        <input type="password" id="new_password" name="new_password" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirmar Contraseña *</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                    
                    <div style="margin-top: 20px; display: flex; gap: 10px;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Cambiar Contraseña
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="cerrarModalPassword()">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function abrirModalUsuario(accion, usuario = null) {
            document.getElementById('accion').value = accion;
            document.getElementById('modalUsuarioTitulo').innerHTML = 
                '<i class="fas fa-user"></i> ' + (accion == 'crear' ? 'Nuevo Usuario' : 'Editar Usuario');
            
            if (accion == 'crear') {
                document.getElementById('formUsuario').reset();
                document.getElementById('password-field').style.display = 'block';
                document.getElementById('activo-field').style.display = 'none';
                document.getElementById('usuario_id').value = '';
            } else {
                document.getElementById('usuario_id').value = usuario.id;
                document.getElementById('nombre').value = usuario.nombre;
                document.getElementById('email').value = usuario.email;
                document.getElementById('rol').value = usuario.rol;
                document.getElementById('telefono').value = usuario.telefono || '';
                document.getElementById('activo').checked = usuario.activo == 1;
                
                document.getElementById('password-field').style.display = 'none';
                document.getElementById('activo-field').style.display = 'block';
            }
            
            document.getElementById('modalUsuario').style.display = 'block';
        }
        
        function cerrarModalUsuario() {
            document.getElementById('modalUsuario').style.display = 'none';
        }
        
        function abrirModalPassword(usuarioId) {
            document.getElementById('password_usuario_id').value = usuarioId;
            document.getElementById('formPassword').reset();
            document.getElementById('modalPassword').style.display = 'block';
        }
        
        function cerrarModalPassword() {
            document.getElementById('modalPassword').style.display = 'none';
        }
        
        function cambiarEstadoUsuario(usuarioId, activo) {
            if (confirm('¿Estás seguro de cambiar el estado del usuario?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                const accionInput = document.createElement('input');
                accionInput.type = 'hidden';
                accionInput.name = 'accion';
                accionInput.value = 'editar';
                form.appendChild(accionInput);
                
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'id';
                idInput.value = usuarioId;
                form.appendChild(idInput);
                
                const activoInput = document.createElement('input');
                activoInput.type = 'hidden';
                activoInput.name = 'activo';
                activoInput.value = activo ? 1 : 0;
                form.appendChild(activoInput);
                
                document.body.appendChild(form);
                form.submit();
            } else {
                // Recargar para revertir el switch visual
                location.reload();
            }
        }
        
        // Validar contraseñas coincidan
        document.getElementById('formPassword').addEventListener('submit', function(e) {
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Las contraseñas no coinciden');
            }
        });
        
        // Cerrar modales al hacer clic fuera
        window.onclick = function(event) {
            const modalUsuario = document.getElementById('modalUsuario');
            const modalPassword = document.getElementById('modalPassword');
            
            if (event.target == modalUsuario) cerrarModalUsuario();
            if (event.target == modalPassword) cerrarModalPassword();
        }
    </script>
</body>
</html>