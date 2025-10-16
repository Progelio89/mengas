<?php
class Database {
    private $servers = [
        'primary' => [
            'serverName' => "DESARROLLO\PLUTONSOTF", 
            'Uid' => "admin",
            'PWD' => "admin"
        ],
        'secondary' => [
            'serverName' => "SRV-PROFIT\CATA",
            'Uid' => "admin",
            'PWD' => "admin"
        ]
    ];
    
    private $empresas = [
        'A' => 'sistema_pagos',
        'B' => 'MX_REPORT',
        'C' => 'MD_REPORT'
    ];
    
    private $connectionOptions;
    public $conn;
    private $currentServer;
    private $basededatos;

    public function __construct($empresa = 'A', $preferredServer = 'auto') {
        $this->basededatos = $this->empresas[$empresa] ?? 'XDTA002';

        $this->connectionOptions = [
            "Database" => $this->basededatos,
            "TrustServerCertificate" => "true",
            "CharacterSet" => "UTF-8",
            "ReturnDatesAsStrings" => true,
            "ConnectionPooling" => false,
            "LoginTimeout" => 5
        ];
        
        $this->currentServer = $preferredServer;
    }

    public function getConnection($preferredServer = 'auto') {
        if ($this->conn && $this->isConnectionAlive()) {
            return $this->conn;
        }
        
        $this->conn = null;
        
        if ($preferredServer === 'auto' || $preferredServer === 'primary') {
            $this->conn = $this->tryConnectToServer('primary');
        }
        
        if (!$this->conn && ($preferredServer === 'auto' || $preferredServer === 'secondary')) {
            $this->conn = $this->tryConnectToServer('secondary');
        }
        
        if (!$this->conn) {
            throw new Exception("No se pudo conectar a ningún servidor disponible");
        }
        
        return $this->conn;
    }
    
    private function tryConnectToServer($serverKey) {
        if (!isset($this->servers[$serverKey])) {
            return null;
        }
        
        $serverConfig = $this->servers[$serverKey];
        $connectionOptions = array_merge(
            $this->connectionOptions,
            [
                "Uid" => $serverConfig['Uid'],
                "PWD" => $serverConfig['PWD']
            ]
        );
        
        $conn = @sqlsrv_connect($serverConfig['serverName'], $connectionOptions);
        
        if ($conn) {
            $this->currentServer = $serverKey;
            error_log("Conexión exitosa al servidor: " . $serverKey . " - Base: " . $this->basededatos);
            return $conn;
        } else {
            $errors = sqlsrv_errors();
            error_log("Error conectando al servidor $serverKey - Base {$this->basededatos}: " . print_r($errors, true));
            return null;
        }
    }
    
    private function isConnectionAlive() {
        if (!$this->conn) {
            return false;
        }
        
        $result = @sqlsrv_query($this->conn, "SELECT 1");
        if ($result) {
            sqlsrv_free_stmt($result);
            return true;
        }
        
        return false;
    }
    
    public function getCurrentServer() {
        return $this->currentServer;
    }
    
    public function getDatabaseName() {
        return $this->basededatos;
    }
    
    // Método para ejecutar consultas
    public function executeQuery($sql, $params = []) {
        $conn = $this->getConnection();
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            throw new Exception("Error en consulta: " . print_r($errors, true));
        }
        
        return $stmt;
    }
    
    // Método para obtener resultados como array
    public function fetchArray($sql, $params = []) {
        $stmt = $this->executeQuery($sql, $params);
        $results = [];
        
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $results[] = $row;
        }
        
        sqlsrv_free_stmt($stmt);
        return $results;
    }
    
    // Método para obtener un solo resultado
    public function fetchSingle($sql, $params = []) {
        $stmt = $this->executeQuery($sql, $params);
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        return $row ?: null;
    }
    
    // Método para insertar y obtener ID
    public function insert($sql, $params = []) {
        $conn = $this->getConnection();
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            throw new Exception("Error en inserción: " . print_r($errors, true));
        }
        
        // Obtener el ID insertado
        $idQuery = "SELECT SCOPE_IDENTITY() as id";
        $idStmt = sqlsrv_query($conn, $idQuery);
        $idRow = sqlsrv_fetch_array($idStmt, SQLSRV_FETCH_ASSOC);
        
        sqlsrv_free_stmt($stmt);
        sqlsrv_free_stmt($idStmt);
        
        return $idRow['id'];
    }
}
?>