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

// Función para log detallado
function logDebug($message) {
    $logFile = __DIR__ . '/debug_detallado.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND | LOCK_EX);
}

class ImportadorMovimientos {
    private $db;

    public function __construct() {
        $this->db = new Database();
    }

    public function importarMovimientos($datos, $nombreArchivo, $idBanco, $usuario) {
        logDebug("=== INICIANDO IMPORTACIÓN ===");
        logDebug("Archivo: $nombreArchivo, Banco: $idBanco, Usuario: $usuario");
        logDebug("Total movimientos recibidos: " . count($datos));
        
        // DEBUG: Mostrar estructura completa de los primeros 3 movimientos
        for ($i = 0; $i < min(3, count($datos)); $i++) {
            logDebug("DEBUG Movimiento $i - Estructura completa:");
            logDebug("  Fecha: '" . ($datos[$i]['fecha'] ?? 'NO EXISTE') . "'");
            logDebug("  Referencia: '" . ($datos[$i]['referencia'] ?? 'NO EXISTE') . "'");
            logDebug("  Descripción: '" . ($datos[$i]['descripcion'] ?? 'NO EXISTE') . "'");
            logDebug("  Monto: '" . ($datos[$i]['monto'] ?? 'NO EXISTE') . "'");
            logDebug("  Tipo: " . (isset($datos[$i]['monto']) ? gettype($datos[$i]['monto']) : 'NO EXISTE'));
        }

        $conn = $this->db->getConnection();
        if (!$conn) {
            logDebug("❌ CONEXIÓN FALLIDA");
            throw new Exception("No se pudo conectar a la base de datos");
        }

        logDebug("✅ Conexión a BD exitosa");

        try {
            // Iniciar transacción
            if (!sqlsrv_begin_transaction($conn)) {
                throw new Exception("No se pudo iniciar transacción");
            }
            logDebug("✅ Transacción iniciada");

            // Insertar archivo
            $sqlArchivo = "INSERT INTO ArchivosImportados (nombre_archivo, id_banco, total_registros, usuario_importacion, fecha_importacion) 
                          VALUES (?, ?, ?, ?, GETDATE())";
            $paramsArchivo = array($nombreArchivo, $idBanco, count($datos), $usuario);
            
            logDebug("SQL Archivo: $sqlArchivo");
            
            $stmtArchivo = sqlsrv_query($conn, $sqlArchivo, $paramsArchivo);
            
            if (!$stmtArchivo) {
                $errors = sqlsrv_errors();
                logDebug("❌ ERROR insertar archivo: " . print_r($errors, true));
                throw new Exception("Error al insertar archivo: " . $errors[0]['message']);
            }
            logDebug("✅ Archivo insertado en tabla ArchivosImportados");

            // Obtener ID del archivo
            $idArchivo = $this->obtenerIdArchivo($conn);
            logDebug("✅ ID Archivo generado: " . ($idArchivo ?: "NULL"));

            if (!$idArchivo) {
                throw new Exception("ID Archivo es NULL - no se pudo obtener el ID identity");
            }

            // Insertar movimientos
            $sqlMovimiento = "INSERT INTO MovimientosBancarios 
                            (id_archivo, id_banco, fecha_movimiento, numero_referencia, descripcion, monto) 
                            VALUES (?, ?, ?, ?, ?, ?)";
            
            logDebug("SQL Movimiento: $sqlMovimiento");

            $movimientosInsertados = 0;
            $movimientosFallidos = 0;
            
            foreach ($datos as $index => $movimiento) {
                try {
                    logDebug("--- Procesando movimiento $index ---");
                    
                    // DEBUG: Mostrar datos crudos
                    logDebug("Datos CRUDOS movimiento $index: " . json_encode($movimiento));

                    // VALIDACIÓN MEJORADA de fecha
                    $fecha = $this->validarYFormatearFecha($movimiento['fecha'] ?? '');
                    if (!$fecha) {
                        logDebug("❌ Movimiento $index - FECHA INVÁLIDA: '" . ($movimiento['fecha'] ?? 'NULL') . "'");
                        $movimientosFallidos++;
                        continue;
                    }

                    // VALIDACIÓN MEJORADA de monto
                    $monto = $this->formatearMonto($movimiento['monto'] ?? '');
                    if ($monto === false) {
                        logDebug("❌ Movimiento $index - MONTO INVÁLIDO: '" . ($movimiento['monto'] ?? 'NULL') . "'");
                        $movimientosFallidos++;
                        continue;
                    }

                    // Preparar parámetros con valores por defecto
                    $referencia = !empty($movimiento['referencia']) ? 
                                substr(trim($movimiento['referencia']), 0, 100) : 'SIN_REFERENCIA';
                    
                    $descripcion = !empty($movimiento['descripcion']) ? 
                                 substr(trim($movimiento['descripcion']), 0, 500) : 'SIN_DESCRIPCION';

                    $params = array(
                        $idArchivo,
                        $idBanco,
                        $fecha,
                        $referencia,
                        $descripcion,
                        $monto
                    );

                    logDebug("Params movimiento $index: " . print_r($params, true));

                    $stmt = sqlsrv_query($conn, $sqlMovimiento, $params);
                    if ($stmt) {
                        $movimientosInsertados++;
                        logDebug("✅ MOVIMIENTO $index INSERTADO EXITOSAMENTE");
                        logDebug("   Referencia: $referencia");
                        logDebug("   Descripción: $descripcion"); 
                        logDebug("   Monto: $monto");
                        logDebug("   Fecha: $fecha");
                    } else {
                        $movimientosFallidos++;
                        $errors = sqlsrv_errors();
                        logDebug("❌ ERROR BD movimiento $index: " . print_r($errors, true));
                        logDebug("   SQL: $sqlMovimiento");
                        logDebug("   Params: " . print_r($params, true));
                    }
                    
                } catch (Exception $e) {
                    $movimientosFallidos++;
                    logDebug("❌ EXCEPCIÓN movimiento $index: " . $e->getMessage());
                }
            }

            // Confirmar transacción
            sqlsrv_commit($conn);
            logDebug("=== IMPORTACIÓN COMPLETADA ===");
            logDebug("Insertados: $movimientosInsertados, Fallidos: $movimientosFallidos");

            return [
                'success' => true,
                'mensaje' => "Importación completada: $movimientosInsertados movimientos importados, $movimientosFallidos fallidos",
                'id_archivo' => $idArchivo,
                'total_procesados' => count($datos),
                'total_insertados' => $movimientosInsertados,
                'total_fallidos' => $movimientosFallidos
            ];

        } catch (Exception $e) {
            if ($conn) {
                sqlsrv_rollback($conn);
            }
            logDebug("=== ERROR EN IMPORTACIÓN: " . $e->getMessage());
            throw $e;
        } finally {
            if ($conn) {
                sqlsrv_close($conn);
            }
        }
    }

    private function obtenerIdArchivo($conn) {
        // Método 1: SCOPE_IDENTITY
        $sqlId = "SELECT SCOPE_IDENTITY() as id_archivo";
        $stmtId = sqlsrv_query($conn, $sqlId);
        
        if ($stmtId && sqlsrv_fetch($stmtId)) {
            $id = sqlsrv_get_field($stmtId, 0);
            if ($id) {
                logDebug("✅ ID obtenido por SCOPE_IDENTITY: $id");
                return $id;
            }
        }

        // Método 2: MAX id
        $sqlAlt = "SELECT MAX(id_archivo) as id_archivo FROM ArchivosImportados";
        $stmtAlt = sqlsrv_query($conn, $sqlAlt);
        
        if ($stmtAlt && sqlsrv_fetch($stmtAlt)) {
            $id = sqlsrv_get_field($stmtAlt, 0);
            logDebug("✅ ID obtenido por MAX: $id");
            return $id;
        }

        logDebug("❌ No se pudo obtener ID archivo");
        return null;
    }

    private function formatearMonto($monto) {
        logDebug("Formateando monto: '$monto' (tipo: " . gettype($monto) . ")");
        
        // Si ya es numérico
        if (is_numeric($monto)) {
            $resultado = floatval($monto);
            logDebug("Monto numérico directo: $resultado");
            return $resultado;
        }
        
        // Si es string, limpiar formato europeo
        if (is_string($monto)) {
            // Remover espacios y caracteres extraños
            $montoLimpio = trim($monto);
            $montoLimpio = str_replace(' ', '', $montoLimpio);
            $montoLimpio = str_replace('Bs.', '', $montoLimpio);
            $montoLimpio = str_replace('$', '', $montoLimpio);
            
            // Formato europeo: 3.255,67 -> 3255.67
            // Remover puntos de miles y cambiar coma decimal por punto
            if (strpos($montoLimpio, ',') !== false && strpos($montoLimpio, '.') !== false) {
                // Tiene ambos: formato europeo (3.255,67)
                $montoLimpio = str_replace('.', '', $montoLimpio); // Eliminar puntos de miles
                $montoLimpio = str_replace(',', '.', $montoLimpio); // Coma decimal a punto
            } elseif (strpos($montoLimpio, ',') !== false) {
                // Solo tiene coma: posible formato decimal europeo
                $montoLimpio = str_replace(',', '.', $montoLimpio);
            }
            
            logDebug("Monto limpio: '$montoLimpio'");
            
            if (is_numeric($montoLimpio)) {
                $resultado = floatval($montoLimpio);
                logDebug("Monto formateado exitosamente: $resultado");
                return $resultado;
            }
        }
        
        logDebug("❌ No se pudo formatear monto: '$monto'");
        return false;
    }

    private function validarYFormatearFecha($fecha) {
        logDebug("Validando fecha: '$fecha'");
        
        if (empty($fecha)) {
            return false;
        }
        
        // Si ya es formato YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            logDebug("Fecha ya en formato correcto: $fecha");
            return $fecha;
        }
        
        // Intentar convertir desde otros formatos
        $timestamp = strtotime($fecha);
        if ($timestamp !== false) {
            $fechaFormateada = date('Y-m-d', $timestamp);
            logDebug("Fecha convertida: '$fecha' -> '$fechaFormateada'");
            return $fechaFormateada;
        }
        
        logDebug("❌ Fecha inválida: '$fecha'");
        return false;
    }
}

// Limpiar buffer de salida
if (ob_get_length()) ob_clean();

// Procesar solicitud POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        // Leer el input JSON
        $input = file_get_contents('php://input');
        logDebug("=== INPUT JSON COMPLETO ===");
        logDebug($input);
        logDebug("=== FIN INPUT JSON ===");
        
        $data = json_decode($input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON inválido: ' . json_last_error_msg());
        }

        if (!isset($data['action']) || $data['action'] !== 'importar') {
            throw new Exception('Acción no especificada');
        }

        if (!isset($data['movimientos']) || !is_array($data['movimientos'])) {
            throw new Exception('Datos de movimientos inválidos');
        }

        $importador = new ImportadorMovimientos();
        
        $resultado = $importador->importarMovimientos(
            $data['movimientos'],
            $data['nombre_archivo'] ?? 'archivo_desconocido',
            $data['id_banco'] ?? 1,
            $data['usuario'] ?? 'admin'
        );

        echo json_encode($resultado);
        
    } catch (Exception $e) {
        logDebug("ERROR GENERAL: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'mensaje' => 'Error: ' . $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'success' => false, 
        'mensaje' => 'Método no permitido. Use POST.'
    ]);
}
?>