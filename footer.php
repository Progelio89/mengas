            </div>
        </div>
    </div>

    <script>
        // Cargar tasa BCV actual
        function cargarTasaBCV() {
            fetch('api/tasa_bcv.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const tasaElement = document.getElementById('tasa-bcv');
                        if (tasaElement) {
                            tasaElement.textContent = 'Bs ' + data.tasa.toFixed(2).replace('.', ',');
                        }
                        const fechaElement = document.getElementById('tasa-fecha');
                        if (fechaElement) {
                            fechaElement.textContent = 'Actualizada: ' + new Date().toLocaleDateString('es-ES');
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                });
        }

        // Cargar tasa al iniciar si existe el elemento
        if (document.getElementById('tasa-bcv')) {
            cargarTasaBCV();
            // Actualizar cada 5 minutos
            setInterval(cargarTasaBCV, 300000);
        }
    </script>
</body>
</html>