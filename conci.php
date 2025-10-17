<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Conciliación Bancaria</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-color: #ecf0f1;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .navbar-brand {
            font-weight: 700;
        }
        
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        
        .card-header {
            background-color: var(--primary-color);
            color: white;
            border-radius: 10px 10px 0 0 !important;
            font-weight: 600;
        }
        
        .btn-primary {
            background-color: var(--secondary-color);
            border: none;
        }
        
        .btn-success {
            background-color: var(--success-color);
            border: none;
        }
        
        .table-container {
            max-height: 500px;
            overflow-y: auto;
            border-radius: 5px;
        }
        
        .match {
            background-color: rgba(39, 174, 96, 0.1) !important;
            border-left: 4px solid var(--success-color);
        }
        
        .no-match {
            background-color: rgba(231, 76, 60, 0.1) !important;
            border-left: 4px solid var(--danger-color);
        }
        
        .suspicious {
            background-color: rgba(243, 156, 18, 0.1) !important;
            border-left: 4px solid var(--warning-color);
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 30px;
        }
        
        .stats-card {
            transition: transform 0.3s;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
        }
        
        .file-upload-area {
            border: 2px dashed #dee2e6;
            border-radius: 10px;
            padding: 30px;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .file-upload-area:hover {
            border-color: var(--secondary-color);
            background-color: rgba(52, 152, 219, 0.05);
        }
        
        .file-upload-area.dragover {
            border-color: var(--success-color);
            background-color: rgba(39, 174, 96, 0.1);
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .status-matched {
            background-color: var(--success-color);
            color: white;
        }
        
        .status-unmatched {
            background-color: var(--danger-color);
            color: white;
        }
        
        .status-suspicious {
            background-color: var(--warning-color);
            color: white;
        }
        
        .filters {
            background-color: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .highlight {
            background-color: #fff9c4 !important;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark" style="background-color: var(--primary-color);">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-file-invoice-dollar me-2"></i>
                Sistema de Conciliación Bancaria
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="#"><i class="fas fa-home me-1"></i> Inicio</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#"><i class="fas fa-history me-1"></i> Historial</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#"><i class="fas fa-cog me-1"></i> Configuración</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <!-- Panel de subida de archivo -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-upload me-2"></i> Subir Extracto Bancario
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8">
                        <div class="file-upload-area" id="dropArea">
                            <i class="fas fa-file-excel fa-3x text-success mb-3"></i>
                            <h5>Arrastra tu archivo Excel aquí</h5>
                            <p class="text-muted">Formatos soportados: .xlsx, .xls</p>
                            <button class="btn btn-primary mt-2" id="browseBtn">
                                <i class="fas fa-search me-1"></i> Seleccionar archivo
                            </button>
                            <input type="file" id="excelFile" accept=".xlsx, .xls" hidden>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="alert alert-info">
                            <h6><i class="fas fa-info-circle me-1"></i> Instrucciones:</h6>
                            <ul class="small ps-3 mb-0">
                                <li>Descarga el extracto bancario desde tu banca online</li>
                                <li>Asegúrate de que incluya: fecha, concepto, monto y referencia</li>
                                <li>El sistema comparará automáticamente con los pagos registrados</li>
                                <li>Los resultados se mostrarán en la tabla de abajo</li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <div class="mt-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <span id="fileName" class="text-muted">Ningún archivo seleccionado</span>
                        <button id="processBtn" class="btn btn-success" disabled>
                            <i class="fas fa-cogs me-1"></i> Procesar Conciliación
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Indicador de carga -->
        <div id="loadingIndicator" class="loading">
            <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
                <span class="visually-hidden">Procesando...</span>
            </div>
            <h4 class="mt-3">Procesando archivo bancario</h4>
            <p class="text-muted">Comparando con los registros de pago del sistema...</p>
        </div>

        <!-- Panel de estadísticas -->
        <div id="statsSection" class="hidden">
            <div class="row">
                <div class="col-md-3">
                    <div class="card stats-card bg-primary text-white">
                        <div class="card-body text-center">
                            <i class="fas fa-file-excel fa-2x mb-2"></i>
                            <h3 id="fileRecords">0</h3>
                            <p class="mb-0">Registros Bancarios</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stats-card bg-success text-white">
                        <div class="card-body text-center">
                            <i class="fas fa-check-circle fa-2x mb-2"></i>
                            <h3 id="matchCount">0</h3>
                            <p class="mb-0">Conciliados</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stats-card bg-danger text-white">
                        <div class="card-body text-center">
                            <i class="fas fa-times-circle fa-2x mb-2"></i>
                            <h3 id="noMatchCount">0</h3>
                            <p class="mb-0">No Conciliados</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stats-card bg-warning text-white">
                        <div class="card-body text-center">
                            <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
                            <h3 id="suspiciousCount">0</h3>
                            <p class="mb-0">Sospechosos</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div id="filtersSection" class="filters hidden">
            <div class="row">
                <div class="col-md-3">
                    <label for="statusFilter" class="form-label">Filtrar por estado:</label>
                    <select id="statusFilter" class="form-select">
                        <option value="all">Todos los estados</option>
                        <option value="matched">Conciliados</option>
                        <option value="unmatched">No conciliados</option>
                        <option value="suspicious">Sospechosos</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="dateFilter" class="form-label">Filtrar por fecha:</label>
                    <input type="date" id="dateFilter" class="form-control">
                </div>
                <div class="col-md-4">
                    <label for="searchFilter" class="form-label">Buscar:</label>
                    <input type="text" id="searchFilter" class="form-control" placeholder="Buscar por referencia, concepto...">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button id="exportBtn" class="btn btn-outline-success w-100">
                        <i class="fas fa-download me-1"></i> Exportar
                    </button>
                </div>
            </div>
        </div>

        <!-- Resultados de conciliación -->
        <div id="resultsSection" class="hidden">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-table me-2"></i> Resultados de Conciliación</h5>
                    <div>
                        <button id="toggleDetails" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-expand me-1"></i> Ver detalles
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-container">
                        <table id="resultsTable" class="table table-hover mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Fecha Mov.</th>
                                    <th>Referencia</th>
                                    <th>Concepto Bancario</th>
                                    <th>Monto Bancario</th>
                                    <th>Cliente</th>
                                    <th>Monto Sistema</th>
                                    <th>Diferencia</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Los datos se llenarán dinámicamente -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Elementos del DOM
        const dropArea = document.getElementById('dropArea');
        const browseBtn = document.getElementById('browseBtn');
        const excelFile = document.getElementById('excelFile');
        const processBtn = document.getElementById('processBtn');
        const fileName = document.getElementById('fileName');
        const loadingIndicator = document.getElementById('loadingIndicator');
        const statsSection = document.getElementById('statsSection');
        const filtersSection = document.getElementById('filtersSection');
        const resultsSection = document.getElementById('resultsSection');
        const resultsTable = document.getElementById('resultsTable');
        const statusFilter = document.getElementById('statusFilter');
        const dateFilter = document.getElementById('dateFilter');
        const searchFilter = document.getElementById('searchFilter');
        const exportBtn = document.getElementById('exportBtn');
        const toggleDetails = document.getElementById('toggleDetails');

        // Eventos de subida de archivo
        browseBtn.addEventListener('click', () => excelFile.click());
        
        excelFile.addEventListener('change', function() {
            if (this.files.length > 0) {
                fileName.textContent = this.files[0].name;
                processBtn.disabled = false;
            }
        });

        // Arrastrar y soltar
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropArea.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            dropArea.addEventListener(eventName, highlight, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropArea.addEventListener(eventName, unhighlight, false);
        });

        function highlight() {
            dropArea.classList.add('dragover');
        }

        function unhighlight() {
            dropArea.classList.remove('dragover');
        }

        dropArea.addEventListener('drop', handleDrop, false);

        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            
            if (files.length > 0) {
                excelFile.files = files;
                fileName.textContent = files[0].name;
                processBtn.disabled = false;
            }
        }

        // Procesar archivo
        processBtn.addEventListener('click', function() {
            if (!excelFile.files.length) {
                alert('Por favor seleccione un archivo Excel');
                return;
            }
            
            // Mostrar indicador de carga
            loadingIndicator.style.display = 'block';
            statsSection.classList.add('hidden');
            filtersSection.classList.add('hidden');
            resultsSection.classList.add('hidden');
            
            // Simular procesamiento (en un entorno real, aquí se enviaría al servidor)
            setTimeout(processFile, 2500);
        });
        
        function processFile() {
            // Ocultar indicador de carga
            loadingIndicator.style.display = 'none';
            
            // Mostrar secciones
            statsSection.classList.remove('hidden');
            filtersSection.classList.remove('hidden');
            resultsSection.classList.remove('hidden');
            
            // Generar datos de ejemplo para demostración
            const sampleData = generateSampleData();
            displayResults(sampleData);
        }
        
        function generateSampleData() {
            const results = [];
            const clients = ['Cliente A', 'Cliente B', 'Cliente C', 'Cliente D', 'Cliente E'];
            const concepts = [
                'Pago de factura', 'Transferencia recibida', 'Abono por servicios', 
                'Pago con tarjeta', 'Depósito en efectivo', 'Devolución de pago'
            ];
            
            for (let i = 1; i <= 35; i++) {
                const bankAmount = (Math.random() * 5000 + 100).toFixed(2);
                let systemAmount, status;
                
                // Determinar estado basado en probabilidad
                const rand = Math.random();
                if (rand < 0.6) {
                    // Coincide
                    systemAmount = bankAmount;
                    status = 'matched';
                } else if (rand < 0.85) {
                    // No coincide
                    systemAmount = (parseFloat(bankAmount) + (Math.random() * 200 - 100)).toFixed(2);
                    status = 'unmatched';
                } else {
                    // Sospechoso
                    systemAmount = (parseFloat(bankAmount) * (0.5 + Math.random() * 0.5)).toFixed(2);
                    status = 'suspicious';
                }
                
                const difference = (parseFloat(systemAmount) - parseFloat(bankAmount)).toFixed(2);
                const client = clients[Math.floor(Math.random() * clients.length)];
                const concept = concepts[Math.floor(Math.random() * concepts.length)];
                
                results.push({
                    id: i,
                    date: new Date(2023, Math.floor(Math.random() * 12), Math.floor(Math.random() * 28) + 1),
                    reference: `REF-${i.toString().padStart(5, '0')}`,
                    bankConcept: concept,
                    bankAmount: parseFloat(bankAmount),
                    client: client,
                    systemAmount: parseFloat(systemAmount),
                    difference: parseFloat(difference),
                    status: status
                });
            }
            
            return results;
        }
        
        function displayResults(data) {
            const tableBody = document.querySelector('#resultsTable tbody');
            tableBody.innerHTML = '';
            
            let matchCount = 0;
            let noMatchCount = 0;
            let suspiciousCount = 0;
            
            data.forEach(item => {
                const row = document.createElement('tr');
                
                // Determinar clase según estado
                if (item.status === 'matched') {
                    row.className = 'match';
                    matchCount++;
                } else if (item.status === 'unmatched') {
                    row.className = 'no-match';
                    noMatchCount++;
                } else {
                    row.className = 'suspicious';
                    suspiciousCount++;
                }
                
                // Determinar badge según estado
                let statusBadge;
                if (item.status === 'matched') {
                    statusBadge = '<span class="status-badge status-matched">Conciliado</span>';
                } else if (item.status === 'unmatched') {
                    statusBadge = '<span class="status-badge status-unmatched">No Conciliado</span>';
                } else {
                    statusBadge = '<span class="status-badge status-suspicious">Sospechoso</span>';
                }
                
                row.innerHTML = `
                    <td>${item.date.toLocaleDateString()}</td>
                    <td>${item.reference}</td>
                    <td>${item.bankConcept}</td>
                    <td>$${item.bankAmount.toFixed(2)}</td>
                    <td>${item.client}</td>
                    <td>$${item.systemAmount.toFixed(2)}</td>
                    <td>$${item.difference.toFixed(2)}</td>
                    <td>${statusBadge}</td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" title="Ver detalles">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-success" title="Conciliar manualmente">
                            <i class="fas fa-check"></i>
                        </button>
                    </td>
                `;
                
                tableBody.appendChild(row);
            });
            
            // Actualizar estadísticas
            document.getElementById('fileRecords').textContent = data.length;
            document.getElementById('matchCount').textContent = matchCount;
            document.getElementById('noMatchCount').textContent = noMatchCount;
            document.getElementById('suspiciousCount').textContent = suspiciousCount;
            
            // Aplicar filtros iniciales
            applyFilters();
        }
        
        // Funciones de filtrado
        function applyFilters() {
            const statusValue = statusFilter.value;
            const dateValue = dateFilter.value;
            const searchValue = searchFilter.value.toLowerCase();
            
            const rows = document.querySelectorAll('#resultsTable tbody tr');
            
            rows.forEach(row => {
                let showRow = true;
                
                // Filtro por estado
                if (statusValue !== 'all') {
                    const statusClass = row.className;
                    if (statusValue === 'matched' && !statusClass.includes('match')) showRow = false;
                    if (statusValue === 'unmatched' && !statusClass.includes('no-match')) showRow = false;
                    if (statusValue === 'suspicious' && !statusClass.includes('suspicious')) showRow = false;
                }
                
                // Filtro por fecha
                if (dateValue) {
                    const rowDate = row.cells[0].textContent;
                    if (rowDate !== dateValue) showRow = false;
                }
                
                // Filtro por búsqueda
                if (searchValue) {
                    const reference = row.cells[1].textContent.toLowerCase();
                    const concept = row.cells[2].textContent.toLowerCase();
                    const client = row.cells[4].textContent.toLowerCase();
                    
                    if (!reference.includes(searchValue) && 
                        !concept.includes(searchValue) && 
                        !client.includes(searchValue)) {
                        showRow = false;
                    } else {
                        // Resaltar coincidencias
                        row.classList.add('highlight');
                    }
                } else {
                    row.classList.remove('highlight');
                }
                
                row.style.display = showRow ? '' : 'none';
            });
        }
        
        // Eventos de filtros
        statusFilter.addEventListener('change', applyFilters);
        dateFilter.addEventListener('change', applyFilters);
        searchFilter.addEventListener('input', applyFilters);
        
        // Función para exportar resultados
        exportBtn.addEventListener('click', function() {
            alert('En un sistema real, esta función exportaría los resultados a Excel o PDF');
        });
        
        // Función para alternar detalles
        toggleDetails.addEventListener('click', function() {
            alert('En un sistema real, esta función mostraría/ocultaría columnas adicionales');
        });
        
        // Inicializar elementos ocultos
        document.querySelectorAll('.hidden').forEach(el => {
            el.style.display = 'none';
        });
    </script>
</body>
</html>