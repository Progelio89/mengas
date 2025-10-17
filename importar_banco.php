
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Conciliación Bancaria</title>
    <script src="https://unpkg.com/xlsx/dist/xlsx.full.min.js"></script>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f5f5f5;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        h1 {
            color: #2c3e50;
            margin-bottom: 30px;
            text-align: center;
            border-bottom: 3px solid #3498db;
            padding-bottom: 10px;
        }

        .form-group {
            margin-bottom: 25px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #3498db;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
        }

        select, input[type="file"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            transition: border-color 0.3s;
        }

        select:focus, input[type="file"]:focus {
            outline: none;
            border-color: #3498db;
        }

        .estructura, .hojas-disponibles {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 15px;
            margin: 15px 0;
        }

        .estructura h4, .hojas-disponibles h4 {
            color: #856404;
            margin-bottom: 10px;
        }

        .vista-previa {
            margin-top: 30px;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 14px;
        }

        th {
            background: #34495e;
            color: white;
            padding: 12px;
            text-align: left;
            position: sticky;
            top: 0;
        }

        td {
            padding: 10px;
            border-bottom: 1px solid #ddd;
        }

        tr:nth-child(even) {
            background: #f8f9fa;
        }

        tr:hover {
            background: #e3f2fd;
        }

        .totales {
            background: #d4edda;
            padding: 15px;
            margin: 15px 0;
            border-radius: 6px;
            border: 1px solid #c3e6cb;
        }

        .totales p {
            margin: 5px 0;
            font-weight: 600;
        }

        #btnImportar {
            background: #28a745;
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.3s;
            display: block;
            margin: 20px auto;
        }

        #btnImportar:hover:not(:disabled) {
            background: #218838;
        }

        #btnImportar:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }

        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #ffffff;
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 1s ease-in-out infinite;
            margin-right: 10px;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            
            table {
                font-size: 12px;
            }
            
            th, td {
                padding: 8px 5px;
            }
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


    <div class="container">
        <h1>📊 Sistema de Conciliación Bancaria</h1>
        
        <div class="form-group">
            <label for="selectBanco">🏦 Seleccionar Banco:</label>
            <select id="selectBanco">
                <option value="">-- Seleccione un banco --</option>
                <option value="1">Provincial</option>
                <option value="2">Banesco</option>
                <option value="3">Banco de Venezuela</option>
                <option value="4">BNC</option>
                <option value="5">Tesoro</option>
                <option value="6">Mercantil</option>
            </select>
        </div>

        <div id="estructuraBanco" class="estructura" style="display: none;">
            <!-- Aquí se mostrará la estructura del banco seleccionado -->
        </div>

        <div class="form-group">
            <label for="archivoExcel">📁 Seleccionar Archivo Excel:</label>
            <input type="file" id="archivoExcel" accept=".xlsx, .xls">
            <small style="color: #666; display: block; margin-top: 5px;">
                El archivo debe contener múltiples hojas, una por cada banco
            </small>
        </div>

        <div id="hojasDisponibles" class="hojas-disponibles" style="display: none;">
            <!-- Aquí se mostrarán las hojas disponibles -->
        </div>

        <div class="vista-previa">
            <h3>👁️ Vista Previa de Datos</h3>
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Referencia</th>
                        <th>Descripción</th>
                        <th>Monto</th>
                        <th>Fecha/Hora</th>
                    </tr>
                </thead>
                <tbody id="vistaPrevia">
                    <tr>
                        <td colspan="5" style="text-align: center; color: #999;">
                            Seleccione un banco y cargue un archivo para ver la vista previa
                        </td>
                    </tr>
                </tbody>
            </table>
            
            <div class="totales">
                <p>📊 Total Registros: <span id="totalRegistros">0</span></p>
                <p>💰 Monto Total: Bs. <span id="totalMonto">0,00</span></p>
            </div>
        </div>

        <button id="btnImportar" disabled>
            ⬆️ Importar Datos
        </button>
    </div>

    <script src="importacion.js"></script>
</body>
</html>