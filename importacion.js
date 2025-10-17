class ImportadorBancario {
    constructor() {
        this.bancoSeleccionado = null;
        this.datosProcesados = [];
        this.workbook = null;
        this.initEventListeners();
    }

    initEventListeners() {
        document.getElementById('archivoExcel').addEventListener('change', (e) => this.procesarArchivo(e));
        document.getElementById('btnImportar').addEventListener('click', () => this.importarDatos());
        document.getElementById('selectBanco').addEventListener('change', (e) => this.cambiarBanco(e));
    }

    cambiarBanco(event) {
        this.bancoSeleccionado = event.target.value;
        this.mostrarEstructuraBanco();
        
        if (this.workbook) {
            this.procesarWorkbook();
        }
    }

    mostrarEstructuraBanco() {
        const estructuras = {
            '1': {
                nombre: 'Provincial',
                columnas: ['Fecha', 'Número Referencia', 'Descripción', 'Monto'],
                hoja: 'provincial csb',
                ejemplo: '10-10-2025 | 169803 | TPBW V0009317294 01082 | 22.600,00'
            },
            '2': {
                nombre: 'Banesco', 
                columnas: ['Fecha', 'Descripción', 'Referencia', 'Monto'],
                hoja: 'banesco csb',
                ejemplo: '10/10/2025 | TRANS.CTAS. A TERCEROS BANESCO | 52836751749 | 9.663,40'
            },
            '3': {
                nombre: 'Venezuela',
                columnas: ['Fecha/Hora', 'Referencia', 'Descripción', 'Monto', 'Tipo'],
                hoja: 'venezuela csb', 
                ejemplo: '10-10-2025 13:55 | 672875669792 | TRANSF RECIBIDA BDV V24565904... | 6.269,48'
            }
        };

        const estructura = estructuras[this.bancoSeleccionado];
        const divEstructura = document.getElementById('estructuraBanco');
        
        if (estructura) {
            divEstructura.style.display = 'block';
            divEstructura.innerHTML = `
                <h4>Estructura para ${estructura.nombre}:</h4>
                <p><strong>Hoja esperada:</strong> "${estructura.hoja}"</p>
                <p><strong>Columnas:</strong> ${estructura.columnas.join(' → ')}</p>
                <p><strong>Ejemplo:</strong> ${estructura.ejemplo}</p>
            `;
        } else {
            divEstructura.style.display = 'none';
        }
    }

    procesarArchivo(event) {
        const file = event.target.files[0];
        if (!file) return;

        const reader = new FileReader();
        
        reader.onload = (e) => {
            try {
                const data = new Uint8Array(e.target.result);
                this.workbook = XLSX.read(data, { type: 'array' });
                
                this.mostrarHojasDisponibles();
                
                if (this.bancoSeleccionado) {
                    this.procesarWorkbook();
                } else {
                    this.mostrarError('Por favor, seleccione un banco primero');
                }
                
            } catch (error) {
                this.mostrarError('Error al procesar archivo: ' + error.message);
            }
        };
        
        reader.onerror = () => {
            this.mostrarError('Error al leer el archivo');
        };
        
        reader.readAsArrayBuffer(file);
    }

    mostrarHojasDisponibles() {
        const hojas = this.workbook.SheetNames;
        const divHojas = document.getElementById('hojasDisponibles');
        
        divHojas.innerHTML = `
            <h4>Hojas encontradas en el archivo:</h4>
            <ul>
                ${hojas.map(hoja => `<li>${hoja}</li>`).join('')}
            </ul>
        `;
        divHojas.style.display = 'block';
    }

    procesarWorkbook() {
        if (!this.bancoSeleccionado || !this.workbook) return;

        const estructuras = {
            '1': { hoja: 'provincial csb', procesador: (datos) => this.procesarProvincial(datos) },
            '2': { hoja: 'banesco csb', procesador: (datos) => this.procesarBanesco(datos) },
            '3': { hoja: 'venezuela csb', procesador: (datos) => this.procesarVenezuela(datos) }
        };

        const config = estructuras[this.bancoSeleccionado];
        if (!config) {
            this.mostrarError('Configuración no encontrada para el banco seleccionado');
            return;
        }

        const nombreHoja = config.hoja;
        const hoja = this.workbook.Sheets[nombreHoja];
        
        if (!hoja) {
            this.mostrarError(`No se encontró la hoja "${nombreHoja}" en el archivo`);
            return;
        }

        const datos = XLSX.utils.sheet_to_json(hoja, { header: 1 });
        this.datosProcesados = config.procesador(datos);
        this.mostrarVistaPrevia();
    }

    procesarProvincial(datos) {
        console.log('Procesando Provincial:', datos);
        const movimientos = [];
        
        for (let i = 0; i < datos.length; i++) {
            const fila = datos[i];
            
            if (fila && fila.length >= 5 && fila[0] && fila[4] !== undefined) {
                if (typeof fila[0] === 'string' && (
                    fila[0].includes('metadata') || 
                    fila[0].includes('sheet_name') ||
                    fila[0] === 'A'
                )) {
                    continue;
                }
                
                const movimiento = {
                    fecha: this.formatearFecha(fila[0]),
                    referencia: fila[1] ? fila[1].toString().trim() : '',
                    descripcion: fila[2] ? fila[2].toString().trim() : '',
                    monto: this.formatearMonto(fila[4]),
                    fecha_hora: null
                };
                
                if (movimiento.fecha && movimiento.monto !== null) {
                    movimientos.push(movimiento);
                }
            }
        }
        
        console.log('Movimientos Provincial procesados:', movimientos);
        return movimientos;
    }

    procesarBanesco(datos) {
        const movimientos = [];
        
        for (let i = 0; i < datos.length; i++) {
            const fila = datos[i];
            
            if (fila.length >= 4 && fila[0] && fila[3]) {
                if (typeof fila[0] === 'string' && fila[0].includes('metadata')) {
                    continue;
                }
                
                const movimiento = {
                    fecha: this.formatearFecha(fila[0]),
                    referencia: fila[2] ? fila[2].toString().trim() : '',
                    descripcion: fila[1] ? fila[1].toString().trim() : '',
                    monto: this.formatearMonto(fila[3]),
                    fecha_hora: null
                };
                
                if (movimiento.fecha && movimiento.monto !== null) {
                    movimientos.push(movimiento);
                }
            }
        }
        
        return movimientos;
    }

    procesarVenezuela(datos) {
        const movimientos = [];
        
        for (let i = 0; i < datos.length; i++) {
            const fila = datos[i];
            
            if (fila.length >= 5 && fila[0] && fila[3]) {
                if (typeof fila[0] === 'string' && fila[0].includes('metadata')) {
                    continue;
                }
                
                const fechaHora = fila[0].toString().trim();
                const movimiento = {
                    fecha: this.formatearFecha(fechaHora.split(' ')[0]),
                    fecha_hora: this.formatearFechaHora(fechaHora),
                    referencia: fila[1] ? fila[1].toString().trim() : '',
                    descripcion: fila[2] ? fila[2].toString().trim() : '',
                    monto: this.formatearMonto(fila[3])
                };
                
                if (movimiento.fecha && movimiento.monto !== null) {
                    movimientos.push(movimiento);
                }
            }
        }
        
        return movimientos;
    }

    formatearFecha(fechaStr) {
        if (!fechaStr) return '';
        
        const str = fechaStr.toString().trim();
        
        try {
            if (str.includes('/')) {
                const parts = str.split('/');
                if (parts.length === 3) {
                    const day = parts[0].padStart(2, '0');
                    const month = parts[1].padStart(2, '0');
                    const year = parts[2].length === 2 ? `20${parts[2]}` : parts[2];
                    return `${year}-${month}-${day}`;
                }
            } else if (str.includes('-')) {
                const parts = str.split('-');
                if (parts.length === 3) {
                    const day = parts[0].padStart(2, '0');
                    const month = parts[1].padStart(2, '0');
                    const year = parts[2].length === 2 ? `20${parts[2]}` : parts[2];
                    return `${year}-${month}-${day}`;
                }
            }
            
            const fecha = new Date(fechaStr);
            if (!isNaN(fecha.getTime())) {
                return fecha.toISOString().split('T')[0];
            }
        } catch (e) {
            console.error('Error formateando fecha:', fechaStr, e);
        }
        
        return '';
    }

    formatearMonto(montoStr) {
        if (!montoStr && montoStr !== 0) {
            console.log('Monto vacío:', montoStr);
            return null;
        }
        
        try {
            const str = montoStr.toString().trim();
            
            // Si ya es un número
            if (!isNaN(str) && !str.includes(',') && !str.includes('.')) {
                return parseFloat(str);
            }
            
            let limpio = str.replace(/[^\d.,-]/g, '');
            
            if (!limpio) {
                return null;
            }
            
            let esNegativo = false;
            if (limpio.startsWith('-')) {
                esNegativo = true;
                limpio = limpio.substring(1);
            }
            
            const tienePuntos = limpio.includes('.');
            const tieneComa = limpio.includes(',');
            
            let numeroFinal;
            
            if (tienePuntos && tieneComa) {
                // Formato: 12.000,00
                numeroFinal = parseFloat(limpio.replace(/\./g, '').replace(',', '.'));
            } else if (tieneComa && !tienePuntos) {
                // Formato: 12000,00
                numeroFinal = parseFloat(limpio.replace(',', '.'));
            } else if (tienePuntos && !tieneComa) {
                // Formato: 12000.00
                numeroFinal = parseFloat(limpio);
            } else {
                numeroFinal = parseFloat(limpio);
            }
            
            if (isNaN(numeroFinal)) {
                console.log('No se pudo convertir:', limpio);
                return null;
            }
            
            if (esNegativo) {
                numeroFinal = -numeroFinal;
            }
            
            return numeroFinal;
            
        } catch (e) {
            console.error('Error formateando monto:', montoStr, e);
            return null;
        }
    }

    formatearFechaHora(fechaHoraStr) {
        if (!fechaHoraStr) return null;
        
        try {
            if (fechaHoraStr.includes(' ')) {
                const [fecha, hora] = fechaHoraStr.split(' ');
                const fechaFormateada = this.formatearFecha(fecha);
                if (fechaFormateada && hora) {
                    return `${fechaFormateada} ${hora}:00`;
                }
            }
            
            const fecha = new Date(fechaHoraStr);
            if (!isNaN(fecha.getTime())) {
                return fecha.toISOString().slice(0, 19).replace('T', ' ');
            }
        } catch (e) {
            console.error('Error formateando fecha/hora:', fechaHoraStr, e);
        }
        
        return null;
    }

    mostrarVistaPrevia() {
        const tabla = document.getElementById('vistaPrevia');
        const totalRegistros = document.getElementById('totalRegistros');
        const totalMonto = document.getElementById('totalMonto');
        
        tabla.innerHTML = '';
        
        if (this.datosProcesados.length === 0) {
            tabla.innerHTML = '<tr><td colspan="5" style="text-align: center; color: #999;">No se encontraron datos válidos</td></tr>';
            totalRegistros.textContent = '0';
            totalMonto.textContent = '0,00';
            document.getElementById('btnImportar').disabled = true;
            return;
        }

        const datosVista = this.datosProcesados.slice(0, 100);
        
        datosVista.forEach(movimiento => {
            const fila = tabla.insertRow();
            fila.innerHTML = `
                <td>${movimiento.fecha || ''}</td>
                <td>${movimiento.referencia || ''}</td>
                <td title="${movimiento.descripcion || ''}">${(movimiento.descripcion || '').substring(0, 50)}${movimiento.descripcion && movimiento.descripcion.length > 50 ? '...' : ''}</td>
                <td style="text-align: right;">${movimiento.monto ? movimiento.monto.toLocaleString('es-VE', {minimumFractionDigits: 2}) : '0,00'}</td>
                <td>${movimiento.fecha_hora || ''}</td>
            `;
        });

        if (this.datosProcesados.length > 100) {
            const filaInfo = tabla.insertRow();
            filaInfo.innerHTML = `<td colspan="5" style="text-align: center; background-color: #fff3cd;">
                Mostrando 100 de ${this.datosProcesados.length} registros
            </td>`;
        }

        totalRegistros.textContent = this.datosProcesados.length.toLocaleString();
        
        const montoTotal = this.datosProcesados.reduce((sum, mov) => sum + (mov.monto || 0), 0);
        totalMonto.textContent = montoTotal.toLocaleString('es-VE', {minimumFractionDigits: 2});
        
        document.getElementById('btnImportar').disabled = false;
    }

    async importarDatos() {
        if (this.datosProcesados.length === 0) {
            this.mostrarError('No hay datos para importar');
            return;
        }

        const btnImportar = document.getElementById('btnImportar');
        const textoOriginal = btnImportar.textContent;
        
        try {
            btnImportar.disabled = true;
            btnImportar.textContent = 'Importando...';
            
            const datosEnvio = {
                action: 'importar',
                movimientos: this.datosProcesados,
                nombre_archivo: document.getElementById('archivoExcel').files[0].name,
                id_banco: this.bancoSeleccionado,
                usuario: 'admin'
            };

            console.log('Enviando datos:', datosEnvio);

            const response = await fetch('importar.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(datosEnvio)
            });

            const textoRespuesta = await response.text();
            console.log('Respuesta del servidor:', textoRespuesta);

            let resultado;
            try {
                resultado = JSON.parse(textoRespuesta);
            } catch (e) {
                throw new Error('Respuesta no válida del servidor: ' + textoRespuesta);
            }

            if (resultado.success) {
                this.mostrarExito(resultado.mensaje);
                this.limpiarFormulario();
            } else {
                this.mostrarError(resultado.mensaje);
            }
        } catch (error) {
            console.error('Error en importación:', error);
            this.mostrarError('Error: ' + error.message);
        } finally {
            btnImportar.disabled = false;
            btnImportar.textContent = textoOriginal;
        }
    }

    limpiarFormulario() {
        document.getElementById('archivoExcel').value = '';
        document.getElementById('vistaPrevia').innerHTML = '<tr><td colspan="5">Seleccione un archivo para ver la vista previa</td></tr>';
        document.getElementById('totalRegistros').textContent = '0';
        document.getElementById('totalMonto').textContent = '0,00';
        document.getElementById('hojasDisponibles').style.display = 'none';
        this.datosProcesados = [];
        this.workbook = null;
    }

    mostrarExito(mensaje) {
        alert('✅ ' + mensaje);
    }

    mostrarError(mensaje) {
        alert('❌ ' + mensaje);
    }
}

// Inicializar
document.addEventListener('DOMContentLoaded', () => {
    new ImportadorBancario();
});