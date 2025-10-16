<?php
header('Content-Type: application/json');
require_once '../config/database.php';

try {
    $db = new Database('A');
    $conn = $db->getConnection();
    
    // Obtener la última tasa activa
    $sql = "SELECT TOP 1 tasa_usd FROM tasas_bcv WHERE activa = 1 ORDER BY fecha_actualizacion DESC";
    $stmt = sqlsrv_query($conn, $sql);
    
    if ($stmt && $tasa = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        echo json_encode([
            'success' => true,
            'tasa' => floatval($tasa['tasa_usd']),
            'fecha' => date('Y-m-d H:i:s')
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'No hay tasa disponible'
        ]);
    }
    
    if ($stmt) sqlsrv_free_stmt($stmt);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>