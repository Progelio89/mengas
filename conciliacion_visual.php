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
$meses_cerrados = [];
$bancos = [];
$error = '';
$success = '';
$vista_activa = isset($_GET['vista']) ? $_GET['vista'] : 'pendientes';

// CARGAR INFORMACIÓN DE BANCOS
function cargarBancos($conn, &$bancos) {
    $sql = "SELECT id_banco, nombre_banco, codigo_banco, activo, codigo FROM dbo.Bancos WHERE activo = 1";
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $bancos[$row['id_banco']] = $row;
        }
    }
}

// VERIFICAR SI EL MES ESTÁ CERRADO
function mesCerrado($conn, $fecha) {
    if ($fecha instanceof DateTime) {
        $mes = $fecha->format('Y-m-01');
    } else {
        $timestamp = strtotime($fecha);
        if ($timestamp === false) {
            return false;
        }
        $mes = date('Y-m-01', $timestamp);
    }
    
    $sql = "SELECT COUNT(*) as count FROM cierres_mes WHERE mes = ? AND cerrado = 1";
    $params = array($mes);
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt === false) {
        return false;
    }
    
    if ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        return $row['count'] > 0;
    }
    
    return false;
}

// FUNCIÓN PARA CERRAR MES
function cerrarMes($conn, $mes, $usuario_id) {
    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
        return "formato_invalido";
    }
    
    $mes_completo = $mes . '-01';
    
    if (mesCerrado($conn, $mes_completo)) {
        return "mes_ya_cerrado";
    }
    
    $sql_pendientes = "SELECT COUNT(*) as pendientes 
                      FROM pagos 
                      WHERE estado IN ('pendiente', 'aprobado') 
                      AND id_movimiento_conciliado IS NULL
                      AND YEAR(fecha_pago) = YEAR(?) 
                      AND MONTH(fecha_pago) = MONTH(?)";
    
    $stmt_pendientes = sqlsrv_query($conn, $sql_pendientes, array($mes_completo, $mes_completo));
    if ($stmt_pendientes !== false && $row = sqlsrv_fetch_array($stmt_pendientes, SQLSRV_FETCH_ASSOC)) {
        if ($row['pendientes'] > 0) {
            return "hay_pendientes";
        }
    }
    
    if (sqlsrv_begin_transaction($conn) === false) {
        return "error_transaccion";
    }
    
    try {
        $sql = "INSERT INTO cierres_mes (mes, cerrado, fecha_cierre, usuario_id) 
                VALUES (?, 1, GETDATE(), ?)";
        $params = array($mes_completo, $usuario_id);
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt === false) {
            sqlsrv_rollback($conn);
            return "error_insercion";
        }
        
        sqlsrv_commit($conn);
        return true;
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        return "error_excepcion";
    }
}

// FUNCIÓN PARA ABRIR MES
function abrirMes($conn, $mes) {
    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
        return "formato_invalido";
    }
    
    $mes_completo = $mes . '-01';
    
    $sql = "DELETE FROM cierres_mes WHERE mes = ?";
    $stmt = sqlsrv_query($conn, $sql, array($mes_completo));
    
    if ($stmt === false) {
        return "error_eliminacion";
    }
    
    return true;
}

// FUNCIÓN PARA CONCILIAR
function conciliarRegistros($conn, $pago_id, $movimiento_id, $usuario_id) {
    // Verificar si el mes está cerrado para el pago
    $sql_fecha_pago = "SELECT fecha_pago FROM pagos WHERE id = ?";
    $stmt_fecha_pago = sqlsrv_query($conn, $sql_fecha_pago, array($pago_id));
    if ($stmt_fecha_pago !== false && $row = sqlsrv_fetch_array($stmt_fecha_pago, SQLSRV_FETCH_ASSOC)) {
        if (mesCerrado($conn, $row['fecha_pago'])) {
            return "mes_cerrado";
        }
    }
    
    // Verificar si el mes está cerrado para el movimiento
    $sql_fecha_mov = "SELECT fecha_movimiento FROM MovimientosBancarios WHERE id_movimiento = ?";
    $stmt_fecha_mov = sqlsrv_query($conn, $sql_fecha_mov, array($movimiento_id));
    if ($stmt_fecha_mov !== false && $row = sqlsrv_fetch_array($stmt_fecha_mov, SQLSRV_FETCH_ASSOC)) {
        if (mesCerrado($conn, $row['fecha_movimiento'])) {
            return "mes_cerrado";
        }
    }
    
    if (sqlsrv_begin_transaction($conn) === false) {
        return false;
    }
    
    try {
        // 1. Actualizar pago
        $sql_pago = "UPDATE dbo.pagos SET estado = 'conciliado', fecha_conciliacion = GETDATE(), id_movimiento_conciliado = ? WHERE id = ?";
        $stmt_pago = sqlsrv_query($conn, $sql_pago, array($movimiento_id, $pago_id));
        if ($stmt_pago === false) return false;
        
        // 2. Actualizar movimiento bancario
        $sql_mov = "UPDATE dbo.MovimientosBancarios SET conciliado = 1, fecha_conciliacion = GETDATE(), id_pago_conciliado = ? WHERE id_movimiento = ?";
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

// FUNCIÓN PARA DESCONCILIAR
function desconciliarRegistros($conn, $pago_id, $movimiento_id) {
    // Verificar si el mes está cerrado para el pago
    $sql_fecha_pago = "SELECT fecha_pago FROM pagos WHERE id = ?";
    $stmt_fecha_pago = sqlsrv_query($conn, $sql_fecha_pago, array($pago_id));
    if ($stmt_fecha_pago !== false && $row = sqlsrv_fetch_array($stmt_fecha_pago, SQLSRV_FETCH_ASSOC)) {
        if (mesCerrado($conn, $row['fecha_pago'])) {
            return "mes_cerrado";
        }
    }
    
    if (sqlsrv_begin_transaction($conn) === false) {
        return false;
    }
    
    try {
        // 1. Actualizar pago
        $sql_pago = "UPDATE dbo.pagos SET estado = 'aprobado', fecha_conciliacion = NULL, id_movimiento_conciliado = NULL WHERE id = ?";
        $stmt_pago = sqlsrv_query($conn, $sql_pago, array($pago_id));
        if ($stmt_pago === false) return false;
        
        // 2. Actualizar movimiento bancario
        $sql_mov = "UPDATE dbo.MovimientosBancarios SET conciliado = 0, fecha_conciliacion = NULL, id_pago_conciliado = NULL WHERE id_movimiento = ?";
        $stmt_mov = sqlsrv_query($conn, $sql_mov, array($movimiento_id));
        if ($stmt_mov === false) return false;
        
        // 3. Eliminar de tabla de conciliaciones
        $sql_conc = "DELETE FROM conciliaciones WHERE pago_id = ? AND movimiento_banco_id = ?";
        $stmt_conc = sqlsrv_query($conn, $sql_conc, array($pago_id, $movimiento_id));
        if ($stmt_conc === false) return false;
        
        sqlsrv_commit($conn);
        return true;
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        return false;
    }
}

// PROCESAR ACCIONES
if ($_POST) {
    // CONCILIACIÓN INDIVIDUAL
    if (isset($_POST['conciliar_individual'])) {
        $pago_id = $_POST['pago_id'];
        $movimiento_id = $_POST['movimiento_id'];
        
        $sql_validacion = "SELECT p.referencia as ref_pago, p.monto_bs as monto_pago, 
                                  m.numero_referencia as ref_mov, m.monto as monto_mov
                           FROM pagos p, MovimientosBancarios m 
                           WHERE p.id = ? AND m.id_movimiento = ?";
        $stmt_validacion = sqlsrv_query($conn, $sql_validacion, array($pago_id, $movimiento_id));
        
        if ($stmt_validacion !== false && $row = sqlsrv_fetch_array($stmt_validacion, SQLSRV_FETCH_ASSOC)) {
            $referencia_coincide = ($row['ref_pago'] == $row['ref_mov']);
            $monto_coincide = (abs($row['monto_pago'] - $row['monto_mov']) < 0.01);
            
            if (!$referencia_coincide || !$monto_coincide) {
                $error = "No se puede conciliar: La referencia o el monto no coinciden exactamente";
            } else {
                $resultado = conciliarRegistros($conn, $pago_id, $movimiento_id, $usuario_id);
                if ($resultado === true) {
                    $success = "Registros conciliados exitosamente";
                } elseif ($resultado === "mes_cerrado") {
                    $error = "No se puede conciliar: El mes está cerrado";
                } else {
                    $error = "Error al conciliar los registros";
                }
            }
        } else {
            $error = "Error al validar los registros";
        }
    }
    
    // CONCILIACIÓN MÚLTIPLE
    if (isset($_POST['conciliar_multiple'])) {
        if (isset($_POST['pares_conciliacion'])) {
            $conciliados = 0;
            $errores = 0;
            $errores_detalle = [];
            
            foreach ($_POST['pares_conciliacion'] as $par) {
                list($pago_id, $movimiento_id) = explode('|', $par);
                
                $sql_validacion = "SELECT p.referencia as ref_pago, p.monto_bs as monto_pago, 
                                          m.numero_referencia as ref_mov, m.monto as monto_mov
                                   FROM pagos p, MovimientosBancarios m 
                                   WHERE p.id = ? AND m.id_movimiento = ?";
                $stmt_validacion = sqlsrv_query($conn, $sql_validacion, array($pago_id, $movimiento_id));
                
                if ($stmt_validacion !== false && $row = sqlsrv_fetch_array($stmt_validacion, SQLSRV_FETCH_ASSOC)) {
                    $referencia_coincide = ($row['ref_pago'] == $row['ref_mov']);
                    $monto_coincide = (abs($row['monto_pago'] - $row['monto_mov']) < 0.01);
                    
                    if (!$referencia_coincide || !$monto_coincide) {
                        $errores++;
                        $errores_detalle[] = "Pago {$row['ref_pago']} - Movimiento {$row['ref_mov']}: Referencia o monto no coinciden";
                        continue;
                    }
                    
                    $resultado = conciliarRegistros($conn, $pago_id, $movimiento_id, $usuario_id);
                    if ($resultado === true) {
                        $conciliados++;
                    } elseif ($resultado === "mes_cerrado") {
                        $errores++;
                        $errores_detalle[] = "Pago {$row['ref_pago']}: Mes cerrado";
                    } else {
                        $errores++;
                        $errores_detalle[] = "Pago {$row['ref_pago']}: Error al conciliar";
                    }
                } else {
                    $errores++;
                    $errores_detalle[] = "Par {$pago_id}|{$movimiento_id}: Error al validar";
                }
            }
            
            if ($conciliados > 0) {
                $success = "$conciliados pares conciliados exitosamente";
            }
            if ($errores > 0) {
                $error = "$errores pares no pudieron ser conciliados. " . implode('; ', $errores_detalle);
            }
        } else {
            $error = "Debe seleccionar al menos un par para conciliar";
        }
    }
    
    // DESCONCILIACIÓN
    if (isset($_POST['desconciliar'])) {
        $pago_id = $_POST['pago_id'];
        $movimiento_id = $_POST['movimiento_id'];
        
        $resultado = desconciliarRegistros($conn, $pago_id, $movimiento_id);
        if ($resultado === true) {
            $success = "Registros desconciliados exitosamente";
        } elseif ($resultado === "mes_cerrado") {
            $error = "No se puede desconciliar: El mes está cerrado";
        } else {
            $error = "Error al desconciliar los registros";
        }
    }
    
    // DESCONCILIACIÓN MÚLTIPLE
    if (isset($_POST['desconciliar_multiple'])) {
        if (isset($_POST['pares_desconciliacion'])) {
            $desconciliados = 0;
            $errores = 0;
            foreach ($_POST['pares_desconciliacion'] as $par) {
                list($pago_id, $movimiento_id) = explode('|', $par);
                $resultado = desconciliarRegistros($conn, $pago_id, $movimiento_id);
                if ($resultado === true) {
                    $desconciliados++;
                } elseif ($resultado === "mes_cerrado") {
                    $errores++;
                } else {
                    $errores++;
                }
            }
            $success = "$desconciliados pares desconciliados exitosamente";
            if ($errores > 0) {
                $error = "$errores pares no pudieron ser desconciliados (posiblemente mes cerrado)";
            }
        } else {
            $error = "Debe seleccionar al menos un par para desconciliar";
        }
    }
    
    // CIERRE DE MES
    if (isset($_POST['cerrar_mes'])) {
        $mes = $_POST['mes'];
        $resultado = cerrarMes($conn, $mes, $usuario_id);
        
        if ($resultado === true) {
            $success = "Mes " . date('F Y', strtotime($mes . '-01')) . " cerrado exitosamente";
        } else {
            switch ($resultado) {
                case "formato_invalido":
                    $error = "Formato de mes inválido. Use YYYY-MM";
                    break;
                case "mes_ya_cerrado":
                    $error = "El mes " . date('F Y', strtotime($mes . '-01')) . " ya está cerrado";
                    break;
                case "hay_pendientes":
                    $error = "No se puede cerrar el mes " . date('F Y', strtotime($mes . '-01')) . ": Hay registros pendientes de conciliar";
                    break;
                case "error_transaccion":
                    $error = "Error al iniciar la transacción de cierre de mes";
                    break;
                case "error_insercion":
                    $error = "Error al guardar el cierre de mes en la base de datos";
                    break;
                default:
                    $error = "Error desconocido al cerrar el mes";
            }
        }
    }
    
    // APERTURA DE MES
    if (isset($_POST['abrir_mes'])) {
        $mes = $_POST['mes'];
        $resultado = abrirMes($conn, $mes);
        
        if ($resultado === true) {
            $success = "Mes " . date('F Y', strtotime($mes . '-01')) . " abierto exitosamente";
        } else {
            switch ($resultado) {
                case "formato_invalido":
                    $error = "Formato de mes inválido. Use YYYY-MM";
                    break;
                case "error_eliminacion":
                    $error = "Error al eliminar el cierre de mes de la base de datos";
                    break;
                default:
                    $error = "Error al abrir el mes";
            }
        }
    }
}

// CARGAR DATOS SEGÚN VISTA ACTIVA
function cargarDatosConciliacion($conn, &$pagos, &$movimientos, &$conciliados, $vista = 'pendientes') {
    if ($vista == 'pendientes') {
        // Pagos no conciliados
        $sql_pagos = "SELECT 
                        p.id, 
                        p.usuario_id, 
                        p.monto, 
                        p.moneda, 
                        p.tasa_aplicada, 
                        p.monto_bs, 
                        p.referencia, 
                        p.fecha_pago, 
                        p.estado, 
                        p.banco_origen,
                        p.banco_destino,
                        p.descripcion,
                        p.cedula_titular,
                        p.id_movimiento_conciliado,
                        u.nombre as cliente_nombre 
                      FROM dbo.pagos p 
                      LEFT JOIN dbo.usuarios u ON p.usuario_id = u.id 
                      WHERE p.estado IN ('pendiente', 'aprobado') AND p.id_movimiento_conciliado IS NULL
                      ORDER BY p.fecha_pago DESC";
        
        $stmt_pagos = sqlsrv_query($conn, $sql_pagos);
        if ($stmt_pagos !== false) {
            while ($row = sqlsrv_fetch_array($stmt_pagos, SQLSRV_FETCH_ASSOC)) {
                if ($row['moneda'] == 'USD' && $row['tasa_aplicada'] > 0) {
                    $row['monto_bs_calculado'] = $row['monto'] * $row['tasa_aplicada'];
                } else {
                    $row['monto_bs_calculado'] = $row['monto_bs'];
                }
                $pagos[] = $row;
            }
        }

        // Movimientos no conciliados
        $sql_movimientos = "SELECT 
                            m.id_movimiento, 
                            m.id_banco,
                            m.fecha_movimiento, 
                            m.numero_referencia, 
                            m.descripcion, 
                            m.monto, 
                            m.tipo_movimiento,
                            m.conciliado,
                            m.fecha_conciliacion,
                            m.id_pago_conciliado,
                            b.nombre_banco,
                            b.codigo_banco
                           FROM dbo.MovimientosBancarios m
                           LEFT JOIN dbo.Bancos b ON m.id_banco = b.id_banco
                           WHERE m.conciliado = 0 
                           ORDER BY m.fecha_movimiento DESC, m.id_movimiento DESC";
        
        $stmt_movimientos = sqlsrv_query($conn, $sql_movimientos);
        if ($stmt_movimientos !== false) {
            while ($row = sqlsrv_fetch_array($stmt_movimientos, SQLSRV_FETCH_ASSOC)) {
                $movimientos[] = $row;
            }
        }
    } else {
        // Datos conciliados
        $sql_conciliados = "SELECT 
                            p.id as pago_id,
                            p.referencia as referencia_pago,
                            p.monto as monto_pago,
                            p.moneda,
                            p.monto_bs,
                            p.fecha_pago,
                            p.banco_origen,
                            u.nombre as cliente_nombre,
                            m.id_movimiento as movimiento_id,
                            m.numero_referencia as referencia_movimiento,
                            m.monto as monto_movimiento,
                            m.id_banco,
                            m.fecha_movimiento,
                            m.descripcion as descripcion_movimiento,
                            b.nombre_banco,
                            b.codigo_banco,
                            c.fecha_conciliacion,
                            c.usuario_id,
                            us.nombre as usuario_conciliacion
                           FROM conciliaciones c
                           INNER JOIN dbo.pagos p ON c.pago_id = p.id
                           INNER JOIN dbo.MovimientosBancarios m ON c.movimiento_banco_id = m.id_movimiento
                           LEFT JOIN dbo.Bancos b ON m.id_banco = b.id_banco
                           INNER JOIN dbo.usuarios u ON p.usuario_id = u.id
                           INNER JOIN dbo.usuarios us ON c.usuario_id = us.id
                           ORDER BY c.fecha_conciliacion DESC";
        
        $stmt_conciliados = sqlsrv_query($conn, $sql_conciliados);
        if ($stmt_conciliados !== false) {
            while ($row = sqlsrv_fetch_array($stmt_conciliados, SQLSRV_FETCH_ASSOC)) {
                $conciliados[] = $row;
            }
        }
    }
}

// CARGAR MESES CERRADOS
function cargarMesesCerrados($conn, &$meses_cerrados) {
    $sql = "SELECT 
                mes, 
                fecha_cierre, 
                u.nombre as usuario_cierre,
                (SELECT COUNT(*) FROM pagos p 
                 WHERE YEAR(p.fecha_pago) = YEAR(cm.mes) 
                 AND MONTH(p.fecha_pago) = MONTH(cm.mes)
                 AND p.estado = 'conciliado') as conciliados_count
            FROM cierres_mes cm
            INNER JOIN dbo.usuarios u ON cm.usuario_id = u.id
            WHERE cerrado = 1
            ORDER BY mes DESC";
    
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $meses_cerrados[] = $row;
        }
    }
}

// Cargar datos
cargarBancos($conn, $bancos);
cargarDatosConciliacion($conn, $pagos, $movimientos, $conciliados, $vista_activa);
cargarMesesCerrados($conn, $meses_cerrados);

// Función para obtener nombre del banco
function obtenerNombreBanco($bancos, $id_banco) {
    return isset($bancos[$id_banco]) ? $bancos[$id_banco]['nombre_banco'] : 'Banco ' . $id_banco;
}

// Función para obtener código del banco
function obtenerCodigoBanco($bancos, $id_banco) {
    return isset($bancos[$id_banco]) ? $bancos[$id_banco]['codigo_banco'] : $id_banco;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Conciliaciones - Sistema Mengas</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .excel-table {
            border: 1px solid #d0d7e5;
            border-radius: 3px;
            background: white;
            font-size: 12px;
        }
        .excel-header {
            background: #f0f0f0;
            border-bottom: 2px solid #d0d7e5;
            font-weight: bold;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .excel-row {
            display: grid;
            border-bottom: 1px solid #eaeaea;
            cursor: pointer;
        }
        .excel-row-pendientes {
            grid-template-columns: 40px 100px 80px 120px 80px 80px 80px;
        }
        .excel-row-conciliados {
            grid-template-columns: 40px 100px 80px 120px 80px 100px 80px 100px 80px;
        }
        .excel-row:hover {
            background-color: #f5f9ff;
        }
        .excel-cell {
            padding: 6px 4px;
            border-right: 1px solid #eaeaea;
            display: flex;
            align-items: center;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 11px;
        }
        .excel-cell:last-child {
            border-right: none;
        }
        .table-container {
            max-height: 60vh;
            overflow-y: auto;
            border: 1px solid #d0d7e5;
        }
        .conciliacion-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin: 20px 0;
        }
        .panel-header {
            color: white;
            padding: 12px 15px;
            border-radius: 5px 5px 0 0;
        }
        .header-pendientes {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .header-conciliados {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        }
        .selected {
            background: #e3f2fd !important;
            border-left: 3px solid #2196f3;
        }
        .matched-exact {
            background: #d4edda !important;
            border-left: 3px solid #28a745;
        }
        .matched-partial {
            background: #fff3cd !important;
            border-left: 3px solid #ffc107;
        }
        .par-seleccionado {
            background: #e3f2fd !important;
            border: 2px solid #2196f3 !important;
        }
        .badge-estado {
            font-size: 10px;
            padding: 2px 5px;
        }
        .search-box {
            background: #f8f9fa;
            padding: 8px;
            border-bottom: 1px solid #dee2e6;
        }
        .resumen-card {
            text-align: center;
            padding: 12px;
            border-radius: 6px;
            color: white;
            margin-bottom: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            font-size: 14px;
        }
        .resumen-pendientes { background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%); }
        .resumen-movimientos { background: linear-gradient(135deg, #a1c4fd 0%, #c2e9fb 100%); }
        .resumen-conciliados { background: linear-gradient(135deg, #84fab0 0%, #8fd3f4 100%); }
        .acciones-conciliacion {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: white;
            padding: 12px;
            border-radius: 8px;
            box-shadow: 0 3px 15px rgba(0,0,0,0.2);
            z-index: 1000;
            display: none;
            min-width: 450px;
            font-size: 12px;
        }
        .banco-badge {
            font-size: 9px;
            padding: 1px 4px;
        }
        .nav-tabs .nav-link.active {
            font-weight: bold;
            border-bottom: 3px solid #0d6efd;
        }
        .mes-cerrado {
            background: #f8d7da !important;
            opacity: 0.7;
        }
        .bloqueado {
            cursor: not-allowed !important;
            opacity: 0.5;
        }
        .cierre-mes-card {
            border-left: 4px solid #dc3545;
        }
        /* Colores para diferentes bancos */
        .banco-provincial { background: linear-gradient(135deg, #dc3545, #c82333); }
        .banco-venezuela { background: linear-gradient(135deg, #28a745, #218838); }
        .banco-banesco { background: linear-gradient(135deg, #17a2b8, #138496); }
        .banco-mercantil { background: linear-gradient(135deg, #ffc107, #e0a800); }
        .banco-bnc { background: linear-gradient(135deg, #6f42c1, #5a2d9c); }
        .banco-default { background: linear-gradient(135deg, #6c757d, #5a6268); }
        .coincidencia-exacta {
            border: 2px solid #28a745;
            box-shadow: 0 0 10px rgba(40, 167, 69, 0.3);
        }
        .btn-conciliar:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .checkbox-conciliacion {
            margin: 0;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <!-- Navbar -->
        <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
            <div class="container">
                <a class="navbar-brand" href="#">
                    <i class="fas fa-balance-scale"></i> Gestión de Conciliaciones
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

            <!-- Pestañas de Navegación -->
            <ul class="nav nav-tabs mb-4" id="myTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $vista_activa == 'pendientes' ? 'active' : ''; ?>" 
                            onclick="cambiarVista('pendientes')">
                        <i class="fas fa-clock"></i> Pendientes de Conciliar
                        <span class="badge bg-warning"><?php echo count($pagos); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $vista_activa == 'conciliados' ? 'active' : ''; ?>" 
                            onclick="cambiarVista('conciliados')">
                        <i class="fas fa-check-double"></i> Conciliados
                        <span class="badge bg-success"><?php echo count($conciliados); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="modal" data-bs-target="#modalCierreMes">
                        <i class="fas fa-lock"></i> Cierre de Mes
                        <span class="badge bg-danger"><?php echo count($meses_cerrados); ?></span>
                    </button>
                </li>
            </ul>

            <?php if ($vista_activa == 'pendientes'): ?>
            <!-- VISTA PENDIENTES DE CONCILIAR -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="resumen-card resumen-pendientes">
                        <i class="fas fa-clock fa-2x mb-2"></i>
                        <h5><?php echo count($pagos); ?></h5>
                        <p class="mb-0">Pagos Pendientes</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="resumen-card resumen-movimientos">
                        <i class="fas fa-university fa-2x mb-2"></i>
                        <h5><?php echo count($movimientos); ?></h5>
                        <p class="mb-0">Movimientos Banco</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="resumen-card resumen-conciliados">
                        <i class="fas fa-check-double fa-2x mb-2"></i>
                        <h5 id="contador-seleccionados">0</h5>
                        <p class="mb-0">Pares Seleccionados</p>
                    </div>
                </div>
            </div>

            <!-- Instrucciones -->
            <div class="alert alert-info">
                <h6><i class="fas fa-info-circle"></i> Instrucciones de Conciliación</h6>
                <p class="mb-2">
                    <strong>Para conciliar correctamente:</strong><br>
                    1. La <strong>referencia</strong> debe ser exactamente igual en ambos registros<br>
                    2. El <strong>monto</strong> debe coincidir exactamente<br>
                    3. Los registros con <span style="background:#d4edda; padding:2px 5px; border-radius:3px;">fondo verde</span> tienen coincidencia exacta y pueden conciliarse<br>
                    4. Puede seleccionar <strong>múltiples pares</strong> y conciliarlos todos a la vez
                </p>
            </div>

            <!-- Panel de Conciliación Visual Tipo Excel -->
            <form method="POST" id="formConciliacion">
                <div class="conciliacion-container">
                    
                    <!-- COLUMNA IZQUIERDA: PAGOS DEL SISTEMA -->
                    <div class="panel">
                        <div class="panel-header header-pendientes">
                            <h6 class="mb-0">
                                <i class="fas fa-database"></i> Pagos del Sistema
                                <span class="badge bg-light text-dark"><?php echo count($pagos); ?></span>
                            </h6>
                        </div>
                        
                        <!-- Búsqueda -->
                        <div class="search-box">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <input type="text" class="form-control" placeholder="Buscar en pagos..." id="searchPagos">
                            </div>
                        </div>
                        
                        <div class="table-container">
                            <div class="excel-table">
                                <!-- Encabezado -->
                                <div class="excel-row excel-row-pendientes excel-header">
                                    <div class="excel-cell">
                                        <input type="checkbox" id="selectAllPagos" onchange="seleccionarTodosPagos(this.checked)">
                                    </div>
                                    <div class="excel-cell">Referencia</div>
                                    <div class="excel-cell">Monto</div>
                                    <div class="excel-cell">Cliente</div>
                                    <div class="excel-cell">Fecha</div>
                                    <div class="excel-cell">Banco Origen</div>
                                    <div class="excel-cell">Estado</div>
                                </div>
                                
                                <!-- Filas de datos -->
                                <?php foreach ($pagos as $index => $pago): 
                                    $fecha_pago = $pago['fecha_pago'] instanceof DateTime ? 
                                        $pago['fecha_pago']->format('d/m/Y') : 
                                        date('d/m/Y', strtotime($pago['fecha_pago']));
                                    
                                    $monto_mostrar = ($pago['moneda'] == 'USD') ? 
                                        '$' . number_format($pago['monto'], 2) : 
                                        'Bs ' . number_format($pago['monto_bs_calculado'], 2);
                                    
                                    $mes_cerrado = mesCerrado($conn, $pago['fecha_pago']);
                                    
                                    $banco_clase = 'banco-default';
                                    if (isset($pago['banco_origen'])) {
                                        $banco_nombre = strtolower($pago['banco_origen']);
                                        if (strpos($banco_nombre, 'provincial') !== false) $banco_clase = 'banco-provincial';
                                        elseif (strpos($banco_nombre, 'venezuela') !== false) $banco_clase = 'banco-venezuela';
                                        elseif (strpos($banco_nombre, 'banesco') !== false) $banco_clase = 'banco-banesco';
                                        elseif (strpos($banco_nombre, 'mercantil') !== false) $banco_clase = 'banco-mercantil';
                                        elseif (strpos($banco_nombre, 'bnc') !== false) $banco_clase = 'banco-bnc';
                                    }
                                ?>
                                <div class="excel-row excel-row-pendientes pago-item <?php echo $mes_cerrado ? 'mes-cerrado bloqueado' : ''; ?>" 
                                     data-pago-id="<?php echo $pago['id']; ?>"
                                     data-monto="<?php echo $pago['monto_bs_calculado']; ?>"
                                     data-referencia="<?php echo htmlspecialchars($pago['referencia']); ?>"
                                     data-cliente="<?php echo htmlspecialchars($pago['cliente_nombre'] ?? ''); ?>"
                                     data-fecha="<?php echo $fecha_pago; ?>"
                                     data-banco="<?php echo htmlspecialchars($pago['banco_origen'] ?? ''); ?>"
                                     onclick="seleccionarPago(this)">
                                    
                                    <div class="excel-cell">
                                        <input type="checkbox" class="checkbox-conciliacion checkbox-pago" 
                                               data-pago-id="<?php echo $pago['id']; ?>"
                                               onchange="toggleParConciliacion(<?php echo $pago['id']; ?>, null, this.checked)"
                                               <?php echo $mes_cerrado ? 'disabled' : ''; ?>>
                                    </div>
                                    <div class="excel-cell referencia-cell"><?php echo $pago['referencia']; ?></div>
                                    <div class="excel-cell monto-cell"><?php echo $monto_mostrar; ?></div>
                                    <div class="excel-cell"><?php echo $pago['cliente_nombre'] ?? 'N/A'; ?></div>
                                    <div class="excel-cell"><?php echo $fecha_pago; ?></div>
                                    <div class="excel-cell">
                                        <span class="badge banco-badge <?php echo $banco_clase; ?>"><?php echo $pago['banco_origen'] ?? 'N/A'; ?></span>
                                    </div>
                                    <div class="excel-cell">
                                        <span class="badge badge-estado bg-warning"><?php echo ucfirst($pago['estado']); ?></span>
                                        <?php if ($mes_cerrado): ?>
                                        <i class="fas fa-lock text-danger ms-1" title="Mes cerrado"></i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                
                                <?php if (empty($pagos)): ?>
                                <div class="excel-row excel-row-pendientes">
                                    <div class="excel-cell" style="grid-column: 1 / -1; text-align: center; padding: 20px;">
                                        <i class="fas fa-receipt fa-2x text-muted mb-2"></i>
                                        <p class="text-muted mb-0">No hay pagos pendientes</p>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- COLUMNA DERECHA: MOVIMIENTOS BANCARIOS -->
                    <div class="panel">
                        <div class="panel-header header-pendientes">
                            <h6 class="mb-0">
                                <i class="fas fa-university"></i> Movimientos Bancarios
                                <span class="badge bg-light text-dark"><?php echo count($movimientos); ?></span>
                            </h6>
                        </div>
                        
                        <!-- Búsqueda -->
                        <div class="search-box">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <input type="text" class="form-control" placeholder="Buscar en movimientos..." id="searchMovimientos">
                            </div>
                        </div>
                        
                        <div class="table-container">
                            <div class="excel-table">
                                <!-- Encabezado -->
                                <div class="excel-row excel-row-pendientes excel-header">
                                    <div class="excel-cell">
                                        <input type="checkbox" id="selectAllMovimientos" onchange="seleccionarTodosMovimientos(this.checked)">
                                    </div>
                                    <div class="excel-cell">Referencia</div>
                                    <div class="excel-cell">Monto</div>
                                    <div class="excel-cell">Banco</div>
                                    <div class="excel-cell">Fecha</div>
                                    <div class="excel-cell">Descripción</div>
                                    <div class="excel-cell">Tipo</div>
                                </div>
                                
                                <!-- Filas de datos -->
                                <?php foreach ($movimientos as $index => $movimiento): 
                                    $fecha_mov = $movimiento['fecha_movimiento'] instanceof DateTime ? 
                                        $movimiento['fecha_movimiento']->format('d/m/Y') : 
                                        date('d/m/Y', strtotime($movimiento['fecha_movimiento']));
                                    
                                    $mes_cerrado = mesCerrado($conn, $movimiento['fecha_movimiento']);
                                    
                                    $nombre_banco = $movimiento['nombre_banco'] ?? obtenerNombreBanco($bancos, $movimiento['id_banco']);
                                    $codigo_banco = $movimiento['codigo_banco'] ?? obtenerCodigoBanco($bancos, $movimiento['id_banco']);
                                    
                                    $banco_clase = 'banco-default';
                                    $banco_nombre = strtolower($nombre_banco);
                                    if (strpos($banco_nombre, 'provincial') !== false) $banco_clase = 'banco-provincial';
                                    elseif (strpos($banco_nombre, 'venezuela') !== false) $banco_clase = 'banco-venezuela';
                                    elseif (strpos($banco_nombre, 'banesco') !== false) $banco_clase = 'banco-banesco';
                                    elseif (strpos($banco_nombre, 'mercantil') !== false) $banco_clase = 'banco-mercantil';
                                    elseif (strpos($banco_nombre, 'bnc') !== false) $banco_clase = 'banco-bnc';
                                ?>
                                <div class="excel-row excel-row-pendientes movimiento-item <?php echo $mes_cerrado ? 'mes-cerrado bloqueado' : ''; ?>" 
                                     data-movimiento-id="<?php echo $movimiento['id_movimiento']; ?>"
                                     data-monto="<?php echo $movimiento['monto']; ?>"
                                     data-referencia="<?php echo htmlspecialchars($movimiento['numero_referencia']); ?>"
                                     data-banco="<?php echo htmlspecialchars($nombre_banco); ?>"
                                     data-fecha="<?php echo $fecha_mov; ?>"
                                     data-descripcion="<?php echo htmlspecialchars($movimiento['descripcion']); ?>"
                                     onclick="seleccionarMovimiento(this)">
                                    
                                    <div class="excel-cell">
                                        <input type="checkbox" class="checkbox-conciliacion checkbox-movimiento" 
                                               data-movimiento-id="<?php echo $movimiento['id_movimiento']; ?>"
                                               onchange="toggleParConciliacion(null, <?php echo $movimiento['id_movimiento']; ?>, this.checked)"
                                               <?php echo $mes_cerrado ? 'disabled' : ''; ?>>
                                    </div>
                                    <div class="excel-cell referencia-cell"><?php echo $movimiento['numero_referencia']; ?></div>
                                    <div class="excel-cell monto-cell">Bs <?php echo number_format($movimiento['monto'], 2); ?></div>
                                    <div class="excel-cell">
                                        <span class="badge banco-badge <?php echo $banco_clase; ?>" title="<?php echo $nombre_banco; ?> (<?php echo $codigo_banco; ?>)">
                                            <?php echo $nombre_banco; ?>
                                        </span>
                                    </div>
                                    <div class="excel-cell"><?php echo $fecha_mov; ?></div>
                                    <div class="excel-cell" title="<?php echo htmlspecialchars($movimiento['descripcion']); ?>">
                                        <?php echo substr($movimiento['descripcion'], 0, 15); ?>...
                                    </div>
                                    <div class="excel-cell">
                                        <span class="badge badge-estado bg-info"><?php echo ucfirst($movimiento['tipo_movimiento'] ?? 'Movimiento'); ?></span>
                                        <?php if ($mes_cerrado): ?>
                                        <i class="fas fa-lock text-danger ms-1" title="Mes cerrado"></i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                
                                <?php if (empty($movimientos)): ?>
                                <div class="excel-row excel-row-pendientes">
                                    <div class="excel-cell" style="grid-column: 1 / -1; text-align: center; padding: 20px;">
                                        <i class="fas fa-file-excel fa-2x text-muted mb-2"></i>
                                        <p class="text-muted mb-0">No hay movimientos bancarios</p>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Panel de Acciones de Conciliación -->
                <div class="acciones-conciliacion" id="accionesConciliacion">
                    <div class="text-center">
                        <h6 class="mb-2">
                            <i class="fas fa-link"></i> Conciliar Registros Seleccionados
                        </h6>
                        
                        <div class="row mb-2">
                            <div class="col-md-6">
                                <strong>Pago Seleccionado:</strong>
                                <div id="infoPago" class="small text-muted">Ninguno</div>
                            </div>
                            <div class="col-md-6">
                                <strong>Movimiento Seleccionado:</strong>
                                <div id="infoMovimiento" class="small text-muted">Ninguno</div>
                            </div>
                        </div>

                        <!-- Información de validación -->
                        <div id="infoValidacion" class="alert alert-warning mb-2" style="display: none;">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span id="textoValidacion"></span>
                        </div>

                        <!-- Botones de acción rápida -->
                        <div class="d-flex gap-1 justify-content-center mb-2">
                            <button type="button" class="btn btn-info btn-sm" onclick="seleccionarTodosExactos()">
                                <i class="fas fa-magic"></i> Seleccionar Coincidencias
                            </button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="limpiarSeleccion()">
                                <i class="fas fa-times"></i> Limpiar
                            </button>
                        </div>
                        
                        <div class="d-flex gap-2 justify-content-center">
                            <button type="submit" name="conciliar_individual" class="btn btn-success btn-sm" id="btnConciliarIndividual" disabled>
                                <i class="fas fa-check-circle"></i> Conciliar Par Individual
                            </button>
                            <button type="submit" name="conciliar_multiple" class="btn btn-primary btn-sm" id="btnConciliarMultiple">
                                <i class="fas fa-layer-group"></i> Conciliar Múltiple (<span id="contadorPares">0</span>)
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Campos ocultos para conciliación individual -->
                <input type="hidden" name="pago_id" id="inputPagoId">
                <input type="hidden" name="movimiento_id" id="inputMovimientoId">
                
                <!-- Campos ocultos para pares de conciliación múltiple -->
                <div id="hiddenParesConciliacion"></div>
            </form>

            <?php elseif ($vista_activa == 'conciliados'): ?>
            <!-- VISTA CONCILIADOS -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="resumen-card resumen-conciliados">
                        <i class="fas fa-check-double fa-2x mb-2"></i>
                        <h5><?php echo count($conciliados); ?></h5>
                        <p class="mb-0">Registros Conciliados</p>
                    </div>
                </div>
            </div>

            <form method="POST" id="formDesconciliacion">
                <div class="panel">
                    <div class="panel-header header-conciliados">
                        <h6 class="mb-0">
                            <i class="fas fa-check-double"></i> Registros Conciliados
                            <span class="badge bg-light text-dark"><?php echo count($conciliados); ?></span>
                        </h6>
                    </div>
                    
                    <!-- Búsqueda -->
                    <div class="search-box">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" class="form-control" placeholder="Buscar en conciliados..." id="searchConciliados">
                        </div>
                    </div>
                    
                    <div class="table-container">
                        <div class="excel-table">
                            <!-- Encabezado -->
                            <div class="excel-row excel-row-conciliados excel-header">
                                <div class="excel-cell">
                                    <input type="checkbox" id="selectAllDesconciliados" onchange="seleccionarTodosDesconciliados(this.checked)">
                                </div>
                                <div class="excel-cell">Ref. Pago</div>
                                <div class="excel-cell">Monto Pago</div>
                                <div class="excel-cell">Cliente</div>
                                <div class="excel-cell">Fecha Pago</div>
                                <div class="excel-cell">Ref. Movimiento</div>
                                <div class="excel-cell">Monto Mov.</div>
                                <div class="excel-cell">Banco</div>
                                <div class="excel-cell">Fecha Conciliación</div>
                            </div>
                            
                            <!-- Filas de datos -->
                            <?php foreach ($conciliados as $index => $conciliado): 
                                $fecha_pago = $conciliado['fecha_pago'] instanceof DateTime ? 
                                    $conciliado['fecha_pago']->format('d/m/Y') : 
                                    date('d/m/Y', strtotime($conciliado['fecha_pago']));
                                
                                $fecha_mov = $conciliado['fecha_movimiento'] instanceof DateTime ? 
                                    $conciliado['fecha_movimiento']->format('d/m/Y') : 
                                    date('d/m/Y', strtotime($conciliado['fecha_movimiento']));
                                
                                $fecha_conciliacion = $conciliado['fecha_conciliacion'] instanceof DateTime ? 
                                    $conciliado['fecha_conciliacion']->format('d/m/Y H:i') : 
                                    date('d/m/Y H:i', strtotime($conciliado['fecha_conciliacion']));
                                
                                $mes_cerrado = mesCerrado($conn, $conciliado['fecha_pago']);
                                
                                $nombre_banco = $conciliado['nombre_banco'] ?? obtenerNombreBanco($bancos, $conciliado['id_banco']);
                                $codigo_banco = $conciliado['codigo_banco'] ?? obtenerCodigoBanco($bancos, $conciliado['id_banco']);
                                
                                $banco_clase = 'banco-default';
                                $banco_nombre = strtolower($nombre_banco);
                                if (strpos($banco_nombre, 'provincial') !== false) $banco_clase = 'banco-provincial';
                                elseif (strpos($banco_nombre, 'venezuela') !== false) $banco_clase = 'banco-venezuela';
                                elseif (strpos($banco_nombre, 'banesco') !== false) $banco_clase = 'banco-banesco';
                                elseif (strpos($banco_nombre, 'mercantil') !== false) $banco_clase = 'banco-mercantil';
                                elseif (strpos($banco_nombre, 'bnc') !== false) $banco_clase = 'banco-bnc';
                            ?>
                            <div class="excel-row excel-row-conciliados conciliado-item <?php echo $mes_cerrado ? 'mes-cerrado bloqueado' : ''; ?>"
                                 data-pago-id="<?php echo $conciliado['pago_id']; ?>"
                                 data-movimiento-id="<?php echo $conciliado['movimiento_id']; ?>">
                                
                                <div class="excel-cell">
                                    <input type="checkbox" class="form-check-input checkbox-desconciliacion" 
                                           name="pares_desconciliacion[]" 
                                           value="<?php echo $conciliado['pago_id']; ?>|<?php echo $conciliado['movimiento_id']; ?>"
                                           onchange="actualizarContadorDesconciliados()"
                                           <?php echo $mes_cerrado ? 'disabled' : ''; ?>>
                                </div>
                                <div class="excel-cell"><?php echo $conciliado['referencia_pago']; ?></div>
                                <div class="excel-cell">
                                    <?php echo ($conciliado['moneda'] == 'USD' ? '$' : 'Bs ') . number_format($conciliado['monto_pago'], 2); ?>
                                </div>
                                <div class="excel-cell"><?php echo $conciliado['cliente_nombre']; ?></div>
                                <div class="excel-cell"><?php echo $fecha_pago; ?></div>
                                <div class="excel-cell"><?php echo $conciliado['referencia_movimiento']; ?></div>
                                <div class="excel-cell">Bs <?php echo number_format($conciliado['monto_movimiento'], 2); ?></div>
                                <div class="excel-cell">
                                    <span class="badge banco-badge <?php echo $banco_clase; ?>" title="<?php echo $nombre_banco; ?> (<?php echo $codigo_banco; ?>)">
                                        <?php echo $nombre_banco; ?>
                                    </span>
                                </div>
                                <div class="excel-cell">
                                    <?php echo $fecha_conciliacion; ?>
                                    <br>
                                    <small class="text-muted">Por: <?php echo $conciliado['usuario_conciliacion']; ?></small>
                                    <?php if ($mes_cerrado): ?>
                                    <br>
                                    <small class="text-danger"><i class="fas fa-lock"></i> Mes cerrado</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php if (empty($conciliados)): ?>
                            <div class="excel-row excel-row-conciliados">
                                <div class="excel-cell" style="grid-column: 1 / -1; text-align: center; padding: 20px;">
                                    <i class="fas fa-check-double fa-2x text-muted mb-2"></i>
                                    <p class="text-muted mb-0">No hay registros conciliados</p>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Acciones para desconciliar -->
                <div class="text-center mt-3">
                    <button type="submit" name="desconciliar_multiple" class="btn btn-warning btn-sm">
                        <i class="fas fa-undo"></i> Desconciliar Seleccionados (<span id="contadorDesconciliados">0</span>)
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="limpiarSeleccionDesconciliados()">
                        <i class="fas fa-times"></i> Limpiar Selección
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal para Cierre de Mes -->
    <div class="modal fade" id="modalCierreMes" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-lock"></i> Gestión de Cierre de Mes</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Formulario para cerrar mes -->
                    <div class="card mb-4">
                        <div class="card-header bg-danger text-white">
                            <h6 class="mb-0"><i class="fas fa-lock"></i> Cerrar Mes</h6>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="row">
                                    <div class="col-md-6">
                                        <label for="mes" class="form-label">Seleccionar Mes:</label>
                                        <input type="month" class="form-control" id="mes" name="mes" required>
                                    </div>
                                    <div class="col-md-6 d-flex align-items-end">
                                        <button type="submit" name="cerrar_mes" class="btn btn-danger">
                                            <i class="fas fa-lock"></i> Cerrar Mes
                                        </button>
                                    </div>
                                </div>
                                <small class="text-muted">Al cerrar un mes, no se podrán modificar las conciliaciones de ese período.</small>
                            </form>
                        </div>
                    </div>

                    <!-- Lista de meses cerrados -->
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h6 class="mb-0"><i class="fas fa-list"></i> Meses Cerrados</h6>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($meses_cerrados)): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Mes</th>
                                                <th>Fecha de Cierre</th>
                                                <th>Usuario</th>
                                                <th>Conciliados</th>
                                                <th>Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($meses_cerrados as $mes_cerrado): 
                                                $fecha_cierre = $mes_cerrado['fecha_cierre'] instanceof DateTime ? 
                                                    $mes_cerrado['fecha_cierre']->format('d/m/Y H:i') : 
                                                    date('d/m/Y H:i', strtotime($mes_cerrado['fecha_cierre']));
                                                $mes_nombre = date('F Y', strtotime($mes_cerrado['mes']));
                                            ?>
                                            <tr>
                                                <td><?php echo $mes_nombre; ?></td>
                                                <td><?php echo $fecha_cierre; ?></td>
                                                <td><?php echo $mes_cerrado['usuario_cierre']; ?></td>
                                                <td>
                                                    <span class="badge bg-info"><?php echo $mes_cerrado['conciliados_count']; ?> registros</span>
                                                </td>
                                                <td>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="mes" value="<?php echo date('Y-m', strtotime($mes_cerrado['mes'])); ?>">
                                                        <button type="submit" name="abrir_mes" class="btn btn-warning btn-sm">
                                                            <i class="fas fa-unlock"></i> Abrir
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="text-muted text-center">No hay meses cerrados</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let pagoSeleccionado = null;
        let movimientoSeleccionado = null;
        let paresConciliacion = new Map(); // Map para almacenar pares: pago_id -> movimiento_id

        function cambiarVista(vista) {
            window.location.href = '?vista=' + vista;
        }

        function seleccionarPago(elemento) {
            if (elemento.classList.contains('bloqueado')) return;
            
            document.querySelectorAll('.pago-item.selected').forEach(item => {
                item.classList.remove('selected');
            });
            
            elemento.classList.add('selected');
            pagoSeleccionado = elemento;
            
            const referencia = elemento.dataset.referencia;
            const monto = elemento.dataset.monto;
            const cliente = elemento.dataset.cliente;
            document.getElementById('infoPago').innerHTML = 
                `<strong>${referencia}</strong><br>Cliente: ${cliente}<br>Monto: Bs ${parseFloat(monto).toFixed(2)}`;
            document.getElementById('inputPagoId').value = elemento.dataset.pagoId;
            
            buscarCoincidenciasExactas();
            mostrarAcciones();
        }

        function seleccionarMovimiento(elemento) {
            if (elemento.classList.contains('bloqueado')) return;
            
            document.querySelectorAll('.movimiento-item.selected').forEach(item => {
                item.classList.remove('selected');
            });
            
            elemento.classList.add('selected');
            movimientoSeleccionado = elemento;
            
            const referencia = elemento.dataset.referencia;
            const monto = elemento.dataset.monto;
            const banco = elemento.dataset.banco;
            document.getElementById('infoMovimiento').innerHTML = 
                `<strong>${referencia}</strong><br>Banco: ${banco}<br>Monto: Bs ${parseFloat(monto).toFixed(2)}`;
            document.getElementById('inputMovimientoId').value = elemento.dataset.movimientoId;
            
            buscarCoincidenciasExactas();
            mostrarAcciones();
        }

        function buscarCoincidenciasExactas() {
            if (!pagoSeleccionado || !movimientoSeleccionado) return;
            
            const montoPago = parseFloat(pagoSeleccionado.dataset.monto);
            const referenciaPago = pagoSeleccionado.dataset.referencia;
            const montoMov = parseFloat(movimientoSeleccionado.dataset.monto);
            const referenciaMov = movimientoSeleccionado.dataset.referencia;
            
            const referenciaCoincideExactamente = (referenciaPago === referenciaMov);
            const montoCoincideExactamente = (Math.abs(montoPago - montoMov) < 0.01);
            
            const infoValidacion = document.getElementById('infoValidacion');
            const textoValidacion = document.getElementById('textoValidacion');
            const btnConciliar = document.getElementById('btnConciliarIndividual');
            
            if (referenciaCoincideExactamente && montoCoincideExactamente) {
                pagoSeleccionado.classList.add('matched-exact');
                movimientoSeleccionado.classList.add('matched-exact');
                pagoSeleccionado.classList.remove('matched-partial');
                movimientoSeleccionado.classList.remove('matched-partial');
                
                infoValidacion.style.display = 'none';
                btnConciliar.disabled = false;
                btnConciliar.classList.remove('btn-secondary');
                btnConciliar.classList.add('btn-success');
                
                // Auto-seleccionar para conciliación múltiple si hay coincidencia exacta
                const pagoId = pagoSeleccionado.dataset.pagoId;
                const movimientoId = movimientoSeleccionado.dataset.movimientoId;
                if (!paresConciliacion.has(pagoId)) {
                    toggleParConciliacion(pagoId, movimientoId, true);
                }
            } else {
                pagoSeleccionado.classList.add('matched-partial');
                movimientoSeleccionado.classList.add('matched-partial');
                pagoSeleccionado.classList.remove('matched-exact');
                movimientoSeleccionado.classList.remove('matched-exact');
                
                let mensajes = [];
                if (!referenciaCoincideExactamente) {
                    mensajes.push(`Referencias diferentes: "${referenciaPago}" vs "${referenciaMov}"`);
                }
                if (!montoCoincideExactamente) {
                    mensajes.push(`Montos diferentes: Bs ${montoPago.toFixed(2)} vs Bs ${montoMov.toFixed(2)}`);
                }
                
                textoValidacion.innerHTML = mensajes.join('<br>');
                infoValidacion.style.display = 'block';
                infoValidacion.className = 'alert alert-warning mb-2';
                btnConciliar.disabled = true;
                btnConciliar.classList.remove('btn-success');
                btnConciliar.classList.add('btn-secondary');
            }
        }

        function toggleParConciliacion(pagoId, movimientoId, checked) {
            if (pagoId && movimientoId) {
                // Par completo
                const parId = `${pagoId}|${movimientoId}`;
                
                if (checked) {
                    paresConciliacion.set(pagoId, movimientoId);
                    
                    // Marcar checkboxes
                    document.querySelectorAll(`.checkbox-pago[data-pago-id="${pagoId}"]`).forEach(cb => cb.checked = true);
                    document.querySelectorAll(`.checkbox-movimiento[data-movimiento-id="${movimientoId}"]`).forEach(cb => cb.checked = true);
                    
                    // Resaltar elementos
                    document.querySelectorAll(`.pago-item[data-pago-id="${pagoId}"]`).forEach(el => el.classList.add('par-seleccionado'));
                    document.querySelectorAll(`.movimiento-item[data-movimiento-id="${movimientoId}"]`).forEach(el => el.classList.add('par-seleccionado'));
                } else {
                    paresConciliacion.delete(pagoId);
                    
                    // Desmarcar checkboxes
                    document.querySelectorAll(`.checkbox-pago[data-pago-id="${pagoId}"]`).forEach(cb => cb.checked = false);
                    document.querySelectorAll(`.checkbox-movimiento[data-movimiento-id="${movimientoId}"]`).forEach(cb => cb.checked = false);
                    
                    // Quitar resaltado
                    document.querySelectorAll(`.pago-item[data-pago-id="${pagoId}"]`).forEach(el => el.classList.remove('par-seleccionado'));
                    document.querySelectorAll(`.movimiento-item[data-movimiento-id="${movimientoId}"]`).forEach(el => el.classList.remove('par-seleccionado'));
                }
            } else if (pagoId) {
                // Solo pago - buscar movimiento correspondiente
                const movimientoId = paresConciliacion.get(pagoId);
                if (movimientoId) {
                    toggleParConciliacion(pagoId, movimientoId, checked);
                }
            } else if (movimientoId) {
                // Solo movimiento - buscar pago correspondiente
                for (let [pId, mId] of paresConciliacion.entries()) {
                    if (mId == movimientoId) {
                        toggleParConciliacion(pId, movimientoId, checked);
                        break;
                    }
                }
            }
            
            actualizarParesConciliacion();
            actualizarContadorPares();
        }

        function actualizarParesConciliacion() {
            const container = document.getElementById('hiddenParesConciliacion');
            container.innerHTML = '';
            
            paresConciliacion.forEach((movimientoId, pagoId) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'pares_conciliacion[]';
                input.value = `${pagoId}|${movimientoId}`;
                container.appendChild(input);
            });
        }

        function actualizarContadorPares() {
            const count = paresConciliacion.size;
            document.getElementById('contadorPares').textContent = count;
            document.getElementById('contador-seleccionados').textContent = count;
            
            const btnMultiple = document.getElementById('btnConciliarMultiple');
            if (count > 0) {
                btnMultiple.disabled = false;
                btnMultiple.classList.remove('btn-secondary');
                btnMultiple.classList.add('btn-primary');
            } else {
                btnMultiple.disabled = true;
                btnMultiple.classList.remove('btn-primary');
                btnMultiple.classList.add('btn-secondary');
            }
        }

        function actualizarContadorDesconciliados() {
            const checkboxes = document.querySelectorAll('input[name="pares_desconciliacion[]"]:checked');
            document.getElementById('contadorDesconciliados').textContent = checkboxes.length;
        }

        function seleccionarTodosPagos(checked) {
            document.querySelectorAll('.checkbox-pago:not(:disabled)').forEach(checkbox => {
                const pagoId = checkbox.dataset.pagoId;
                // Buscar movimiento con referencia y monto coincidente
                const pagoItem = document.querySelector(`.pago-item[data-pago-id="${pagoId}"]`);
                if (pagoItem) {
                    const referencia = pagoItem.dataset.referencia;
                    const monto = pagoItem.dataset.monto;
                    
                    // Buscar movimiento que coincida
                    const movimientoItem = document.querySelector(`.movimiento-item:not(.bloqueado)[data-referencia="${referencia}"]`);
                    if (movimientoItem && Math.abs(parseFloat(movimientoItem.dataset.monto) - parseFloat(monto)) < 0.01) {
                        toggleParConciliacion(pagoId, movimientoItem.dataset.movimientoId, checked);
                    }
                }
            });
        }

        function seleccionarTodosMovimientos(checked) {
            document.querySelectorAll('.checkbox-movimiento:not(:disabled)').forEach(checkbox => {
                const movimientoId = checkbox.dataset.movimientoId;
                const movimientoItem = document.querySelector(`.movimiento-item[data-movimiento-id="${movimientoId}"]`);
                if (movimientoItem) {
                    const referencia = movimientoItem.dataset.referencia;
                    const monto = movimientoItem.dataset.monto;
                    
                    // Buscar pago que coincida
                    const pagoItem = document.querySelector(`.pago-item:not(.bloqueado)[data-referencia="${referencia}"]`);
                    if (pagoItem && Math.abs(parseFloat(pagoItem.dataset.monto) - parseFloat(monto)) < 0.01) {
                        toggleParConciliacion(pagoItem.dataset.pagoId, movimientoId, checked);
                    }
                }
            });
        }

        function seleccionarTodosDesconciliados(checked) {
            document.querySelectorAll('.checkbox-desconciliacion:not(:disabled)').forEach(checkbox => {
                checkbox.checked = checked;
            });
            actualizarContadorDesconciliados();
        }

        function seleccionarTodosExactos() {
            // Buscar todos los pares con coincidencia exacta
            document.querySelectorAll('.pago-item:not(.bloqueado)').forEach(pagoItem => {
                const referencia = pagoItem.dataset.referencia;
                const monto = pagoItem.dataset.monto;
                const pagoId = pagoItem.dataset.pagoId;
                
                // Buscar movimiento que coincida exactamente
                const movimientoItem = document.querySelector(`.movimiento-item:not(.bloqueado)[data-referencia="${referencia}"]`);
                if (movimientoItem && Math.abs(parseFloat(movimientoItem.dataset.monto) - parseFloat(monto)) < 0.01) {
                    toggleParConciliacion(pagoId, movimientoItem.dataset.movimientoId, true);
                }
            });
        }

        function mostrarAcciones() {
            if (pagoSeleccionado && movimientoSeleccionado) {
                document.getElementById('accionesConciliacion').style.display = 'block';
            }
        }

        function limpiarSeleccion() {
            pagoSeleccionado = null;
            movimientoSeleccionado = null;
            paresConciliacion.clear();
            
            document.querySelectorAll('.selected, .matched-exact, .matched-partial, .par-seleccionado').forEach(item => {
                item.classList.remove('selected', 'matched-exact', 'matched-partial', 'par-seleccionado');
            });
            
            document.querySelectorAll('.checkbox-conciliacion').forEach(checkbox => {
                checkbox.checked = false;
            });
            
            document.getElementById('accionesConciliacion').style.display = 'none';
            document.getElementById('infoPago').textContent = 'Ninguno';
            document.getElementById('infoMovimiento').textContent = 'Ninguno';
            document.getElementById('inputPagoId').value = '';
            document.getElementById('inputMovimientoId').value = '';
            document.getElementById('infoValidacion').style.display = 'none';
            
            actualizarParesConciliacion();
            actualizarContadorPares();
        }

        function limpiarSeleccionDesconciliados() {
            document.querySelectorAll('.checkbox-desconciliacion').forEach(checkbox => {
                checkbox.checked = false;
            });
            actualizarContadorDesconciliados();
        }

        // Búsqueda en tiempo real
        document.getElementById('searchPagos')?.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            document.querySelectorAll('.pago-item').forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });

        document.getElementById('searchMovimientos')?.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            document.querySelectorAll('.movimiento-item').forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });

        document.getElementById('searchConciliados')?.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            document.querySelectorAll('.conciliado-item').forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });

        // Validación del formulario
        document.getElementById('formConciliacion')?.addEventListener('submit', function(e) {
            if (e.submitter.name === 'conciliar_individual') {
                if (!confirm('¿Está seguro de que desea conciliar este par de registros?')) {
                    e.preventDefault();
                }
            } else if (e.submitter.name === 'conciliar_multiple') {
                const cantidad = paresConciliacion.size;
                if (cantidad === 0) {
                    alert('Debe seleccionar al menos un par para conciliar');
                    e.preventDefault();
                } else if (!confirm(`¿Está seguro de que desea conciliar ${cantidad} pares de registros?`)) {
                    e.preventDefault();
                }
            }
        });

        document.getElementById('formDesconciliacion')?.addEventListener('submit', function(e) {
            if (e.submitter.name === 'desconciliar_multiple') {
                const cantidad = document.querySelectorAll('input[name="pares_desconciliacion[]"]:checked').length;
                if (cantidad === 0) {
                    alert('Debe seleccionar al menos un par para desconciliar');
                    e.preventDefault();
                } else if (!confirm(`¿Está seguro de que desea desconciliar ${cantidad} pares de registros?`)) {
                    e.preventDefault();
                }
            }
        });

        // Inicializar
        document.addEventListener('DOMContentLoaded', function() {
            actualizarContadorPares();
            actualizarContadorDesconciliados();
        });
    </script>
</body>
</html>

