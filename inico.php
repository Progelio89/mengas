<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mengas - Sistema de Pagos Venezuela</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #CF142B;
            --secondary: #00247D;
            --success: #0D8641;
            --warning: #FFCC02;
            --danger: #D32F2F;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f5f7fb;
            color: var(--dark);
            line-height: 1.6;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 1.5rem 0;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            font-size: 1.8rem;
            font-weight: 700;
            display: flex;
            align-items: center;
        }
        
        .logo i {
            margin-right: 10px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--light);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: var(--primary);
        }
        
        .notification-bell {
            position: relative;
            cursor: pointer;
        }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: var(--warning);
            color: var(--dark);
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        main {
            padding: 2rem 0;
        }
        
        .dashboard {
            display: grid;
            grid-template-columns: 1fr 3fr;
            gap: 2rem;
        }
        
        @media (max-width: 768px) {
            .dashboard {
                grid-template-columns: 1fr;
            }
        }
        
        .sidebar {
            background-color: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            height: fit-content;
        }
        
        .sidebar-menu {
            list-style: none;
        }
        
        .sidebar-menu li {
            margin-bottom: 10px;
        }
        
        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 10px 15px;
            color: var(--dark);
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .sidebar-menu a:hover, .sidebar-menu a.active {
            background-color: var(--primary);
            color: white;
        }
        
        .sidebar-menu i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        
        .content {
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }
        
        .card {
            background-color: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .card-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--dark);
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background-color: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #a81024;
        }
        
        .btn-success {
            background-color: var(--success);
            color: white;
        }
        
        .btn-success:hover {
            background-color: #0a6b33;
        }
        
        .btn-warning {
            background-color: var(--warning);
            color: var(--dark);
        }
        
        .btn-warning:hover {
            background-color: #e6b800;
        }
        
        .btn-danger {
            background-color: var(--danger);
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #b71c1c;
        }
        
        .payment-card {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
        }
        
        .payment-card h3 {
            margin-bottom: 1rem;
            font-size: 1.5rem;
        }
        
        .payment-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .payment-amount {
            font-size: 2rem;
            font-weight: 700;
        }
        
        .payment-due {
            font-size: 1.1rem;
        }
        
        .payment-due.warning {
            color: var(--warning);
        }
        
        .payment-due.danger {
            color: #ff9999;
        }
        
        .currency-selector {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        
        .currency-option {
            padding: 5px 10px;
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
        }
        
        .currency-option.active {
            background-color: var(--warning);
            color: var(--dark);
            border-color: var(--warning);
        }
        
        .payment-methods {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
        }
        
        .payment-method {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 1rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .payment-method:hover {
            border-color: var(--primary);
            transform: translateY(-5px);
        }
        
        .payment-method.selected {
            border-color: var(--primary);
            background-color: rgba(207, 20, 43, 0.05);
        }
        
        .payment-icon {
            font-size: 2rem;
            margin-bottom: 10px;
            color: var(--primary);
        }
        
        .venezuelan-methods {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px dashed #e0e0e0;
        }
        
        .venezuelan-methods .section-title {
            font-size: 1.1rem;
            margin-bottom: 1rem;
            color: var(--secondary);
            font-weight: 600;
        }
        
        .payment-history {
            margin-top: 2rem;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th, .table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        
        .status {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .status.paid {
            background-color: rgba(13, 134, 65, 0.2);
            color: var(--success);
        }
        
        .status.pending {
            background-color: rgba(255, 204, 2, 0.2);
            color: #b38f00;
        }
        
        .status.overdue {
            background-color: rgba(211, 47, 47, 0.2);
            color: var(--danger);
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background-color: white;
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
            padding: 2rem;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .modal-title {
            font-size: 1.3rem;
            font-weight: 600;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray);
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            outline: none;
        }
        
        .card-details {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 1rem;
        }
        
        .payment-details {
            display: none;
        }
        
        .venezuelan-payment-instructions {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-top: 10px;
            font-size: 0.9rem;
        }
        
        .venezuelan-payment-instructions p {
            margin-bottom: 8px;
        }
        
        .venezuelan-payment-instructions strong {
            color: var(--secondary);
        }
        
        .notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 8px;
            color: white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 1100;
            display: flex;
            align-items: center;
            gap: 10px;
            transform: translateX(150%);
            transition: transform 0.5s ease;
        }
        
        .notification.show {
            transform: translateX(0);
        }
        
        .notification.success {
            background-color: var(--success);
        }
        
        .notification.error {
            background-color: var(--danger);
        }
        
        .notification.warning {
            background-color: var(--warning);
            color: var(--dark);
        }
        
        footer {
            background-color: var(--dark);
            color: white;
            padding: 2rem 0;
            margin-top: 3rem;
        }
        
        .footer-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        @media (max-width: 768px) {
            .footer-content {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .card-details {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <div class="logo">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Mengas Venezuela</span>
                </div>
                <div class="user-info">
                    <div class="notification-bell" id="notificationBell">
                        <i class="fas fa-bell"></i>
                        <span class="notification-badge">2</span>
                    </div>
                    <div class="user-avatar">JD</div>
                    <span>Juan Domínguez</span>
                </div>
            </div>
        </div>
    </header>
    
    <main class="container">
        <div class="dashboard">
            <aside class="sidebar">
                <ul class="sidebar-menu">
                    <li><a href="#" class="active"><i class="fas fa-home"></i> Dashboard</a></li>
                    <li><a href="#"><i class="fas fa-credit-card"></i> Pagos</a></li>
                    <li><a href="#"><i class="fas fa-history"></i> Historial</a></li>
                    <li><a href="#"><i class="fas fa-file-invoice"></i> Facturas</a></li>
                    <li><a href="#"><i class="fas fa-exchange-alt"></i> Divisas</a></li>
                    <li><a href="#"><i class="fas fa-cog"></i> Configuración</a></li>
                </ul>
            </aside>
            
            <div class="content">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Próximos Pagos</h2>
                        <button class="btn btn-primary" id="configureReminders">
                            <i class="fas fa-bell"></i> Recordatorios
                        </button>
                    </div>
                    
                    <div class="payment-card">
                        <h3>Pago Pendiente</h3>
                        <div class="payment-info">
                            <div>
                                <div class="payment-amount" id="amountDisplay">$50.00</div>
                                <div class="payment-due warning">Vence en 3 días</div>
                                <div class="currency-selector">
                                    <div class="currency-option active" data-currency="USD">USD</div>
                                    <div class="currency-option" data-currency="VES">VES</div>
                                    <div class="currency-option" data-currency="EUR">EUR</div>
                                </div>
                            </div>
                            <button class="btn btn-success" id="payNowBtn">
                                <i class="fas fa-credit-card"></i> Pagar Ahora
                            </button>
                        </div>
                    </div>
                    
                    <div class="payment-methods">
                        <div class="payment-method" data-method="card">
                            <div class="payment-icon">
                                <i class="fas fa-credit-card"></i>
                            </div>
                            <h4>Tarjeta Internacional</h4>
                            <p>Visa, Mastercard, Amex</p>
                        </div>
                        <div class="payment-method" data-method="transfer">
                            <div class="payment-icon">
                                <i class="fas fa-university"></i>
                            </div>
                            <h4>Transferencia</h4>
                            <p>Transferencia bancaria</p>
                        </div>
                        <div class="payment-method" data-method="zelle">
                            <div class="payment-icon">
                                <i class="fas fa-money-bill-transfer"></i>
                            </div>
                            <h4>Zelle</h4>
                            <p>Pago desde USA</p>
                        </div>
                    </div>
                    
                    <div class="venezuelan-methods">
                        <div class="section-title">Métodos de Pago en Venezuela</div>
                        <div class="payment-methods">
                            <div class="payment-method" data-method="pago-mobile">
                                <div class="payment-icon">
                                    <i class="fas fa-mobile-alt"></i>
                                </div>
                                <h4>Pago Móvil</h4>
                                <p>Desde tu teléfono</p>
                            </div>
                            <div class="payment-method" data-method="binance">
                                <div class="payment-icon">
                                    <i class="fab fa-bitcoin"></i>
                                </div>
                                <h4>Binance</h4>
                                <p>P2P y Cripto</p>
                            </div>
                            <div class="payment-method" data-method="airtm">
                                <div class="payment-icon">
                                    <i class="fas fa-cloud"></i>
                                </div>
                                <h4>Airtm</h4>
                                <p>Billetera digital</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card payment-history">
                    <div class="card-header">
                        <h2 class="card-title">Historial de Pagos</h2>
                    </div>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Descripción</th>
                                <th>Monto</th>
                                <th>Método</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody id="paymentHistoryBody">
                            <tr>
                                <td>15/08/2023</td>
                                <td>Pago de Servicios - Agosto</td>
                                <td>$50.00</td>
                                <td>Pago Móvil</td>
                                <td><span class="status paid">Pagado</span></td>
                            </tr>
                            <tr>
                                <td>15/07/2023</td>
                                <td>Pago de Servicios - Julio</td>
                                <td>Bs. 450.000</td>
                                <td>Transferencia</td>
                                <td><span class="status paid">Pagado</span></td>
                            </tr>
                            <tr>
                                <td>15/06/2023</td>
                                <td>Pago de Servicios - Junio</td>
                                <td>$45.00</td>
                                <td>Zelle</td>
                                <td><span class="status paid">Pagado</span></td>
                            </tr>
                            <tr>
                                <td>15/05/2023</td>
                                <td>Pago de Servicios - Mayo</td>
                                <td>0.0012 BTC</td>
                                <td>Binance</td>
                                <td><span class="status paid">Pagado</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
    
    <!-- Modal de Pago -->
    <div class="modal" id="paymentModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Realizar Pago</h3>
                <button class="close-modal" id="closePaymentModal">&times;</button>
            </div>
            <form id="paymentForm">
                <div class="form-group">
                    <label class="form-label">Método de Pago</label>
                    <select class="form-control" id="paymentMethodSelect">
                        <option value="">Selecciona un método de pago</option>
                        <option value="card">Tarjeta Internacional</option>
                        <option value="transfer">Transferencia Bancaria</option>
                        <option value="zelle">Zelle</option>
                        <option value="pago-mobile">Pago Móvil</option>
                        <option value="binance">Binance</option>
                        <option value="airtm">Airtm</option>
                    </select>
                </div>
                
                <div id="cardDetails" class="payment-details">
                    <div class="form-group">
                        <label class="form-label">Número de Tarjeta</label>
                        <input type="text" class="form-control" placeholder="1234 5678 9012 3456" id="cardNumber">
                    </div>
                    <div class="card-details">
                        <div class="form-group">
                            <label class="form-label">Nombre en la Tarjeta</label>
                            <input type="text" class="form-control" placeholder="Juan Domínguez" id="cardName">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Fecha de Exp.</label>
                            <input type="text" class="form-control" placeholder="MM/AA" id="cardExpiry">
                        </div>
                        <div class="form-group">
                            <label class="form-label">CVV</label>
                            <input type="text" class="form-control" placeholder="123" id="cardCvv">
                        </div>
                    </div>
                </div>
                
                <div id="transferDetails" class="payment-details">
                    <div class="venezuelan-payment-instructions">
                        <p>Realiza una transferencia en USD o VES a la siguiente cuenta:</p>
                        <p><strong>Banco: Banesco</strong></p>
                        <p><strong>Cuenta: 0134-1234-1234-1234-1234</strong></p>
                        <p><strong>Beneficiario: Mengas Venezuela C.A.</strong></p>
                        <p><strong>RIF: J-412345678</strong></p>
                        <p><strong>Enviar comprobante a: pagos@mengas.com.ve</strong></p>
                    </div>
                </div>
                
                <div id="zelleDetails" class="payment-details">
                    <div class="venezuelan-payment-instructions">
                        <p>Realiza el pago vía Zelle a:</p>
                        <p><strong>Email: pagos@mengas.com</strong></p>
                        <p><strong>Nombre: Mengas International</strong></p>
                        <p><strong>Enviar comprobante a: zelle@mengas.com</strong></p>
                        <p><em>Tasa de cambio del día aplicable</em></p>
                    </div>
                </div>
                
                <div id="pagoMobileDetails" class="payment-details">
                    <div class="form-group">
                        <label class="form-label">Número de Teléfono</label>
                        <input type="text" class="form-control" placeholder="0412-1234567" id="phoneNumber">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Cédula de Identidad</label>
                        <input type="text" class="form-control" placeholder="V-12345678" id="idNumber">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Banco</label>
                        <select class="form-control" id="bankSelect">
                            <option value="">Selecciona tu banco</option>
                            <option value="banesco">Banesco</option>
                            <option value="provincial">Banco Provincial</option>
                            <option value="mercantil">Banco Mercantil</option>
                            <option value="venezuela">Banco de Venezuela</option>
                            <option value="bnc">BNC</option>
                        </select>
                    </div>
                    <div class="venezuelan-payment-instructions">
                        <p>Realiza el pago móvil a:</p>
                        <p><strong>Teléfono: 0412-1234567</strong></p>
                        <p><strong>Cédula: V-12345678</strong></p>
                        <p><strong>Banco: Banesco</strong></p>
                        <p><strong>Enviar comprobante a: pm@mengas.com.ve</strong></p>
                    </div>
                </div>
                
                <div id="binanceDetails" class="payment-details">
                    <div class="form-group">
                        <label class="form-label">Método Binance</label>
                        <select class="form-control" id="binanceMethod">
                            <option value="">Selecciona método</option>
                            <option value="p2p">P2P (Bolívares)</option>
                            <option value="usdt">USDT</option>
                            <option value="btc">Bitcoin (BTC)</option>
                            <option value="eth">Ethereum (ETH)</option>
                        </select>
                    </div>
                    <div class="venezuelan-payment-instructions">
                        <p>Para pagar con Binance:</p>
                        <p><strong>1. Realiza la transferencia en la moneda seleccionada</strong></p>
                        <p><strong>2. Nuestra wallet USDT: Txxxxxxxxxxxxxxxx</strong></p>
                        <p><strong>3. O contacta a nuestro vendedor P2P: @MengasPay</strong></p>
                        <p><strong>4. Enviar comprobante a: binance@mengas.com.ve</strong></p>
                    </div>
                </div>
                
                <div id="airtmDetails" class="payment-details">
                    <div class="venezuelan-payment-instructions">
                        <p>Para pagar con Airtm:</p>
                        <p><strong>1. Envía los fondos a nuestro usuario:</strong></p>
                        <p><strong>Usuario: MengasOfficial</strong></p>
                        <p><strong>Email: pagos@mengas.com.ve</strong></p>
                        <p><strong>2. O escanea nuestro código Airtm</strong></p>
                        <p><strong>3. Enviar comprobante a: airtm@mengas.com.ve</strong></p>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Monto a Pagar</label>
                    <input type="text" class="form-control" id="paymentAmount" value="$50.00" readonly>
                </div>
                
                <button type="submit" class="btn btn-success" style="width: 100%;">
                    <i class="fas fa-lock"></i> Confirmar Pago
                </button>
            </form>
        </div>
    </div>
    
    <!-- Modal de Recordatorios -->
    <div class="modal" id="remindersModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Configurar Recordatorios</h3>
                <button class="close-modal" id="closeRemindersModal">&times;</button>
            </div>
            <form id="remindersForm">
                <div class="form-group">
                    <label class="form-label">Notificaciones por Email</label>
                    <select class="form-control" id="emailReminders">
                        <option value="7">7 días antes</option>
                        <option value="5">5 días antes</option>
                        <option value="3" selected>3 días antes</option>
                        <option value="1">1 día antes</option>
                        <option value="0">El mismo día</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Notificaciones por SMS</label>
                    <select class="form-control" id="smsReminders">
                        <option value="3">3 días antes</option>
                        <option value="1" selected>1 día antes</option>
                        <option value="0">El mismo día</option>
                        <option value="none">No enviar</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Notificaciones por Pago Móvil</label>
                    <select class="form-control" id="mobileReminders">
                        <option value="1" selected>1 día antes</option>
                        <option value="0">El mismo día</option>
                        <option value="none">No enviar</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <input type="checkbox" id="autoReminders" checked> Recordatorio automático para pagos recurrentes
                    </label>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-save"></i> Guardar Configuración
                </button>
            </form>
        </div>
    </div>
    
    <!-- Notificación -->
    <div class="notification" id="notification">
        <i class="fas fa-check-circle" id="notificationIcon"></i>
        <span id="notificationText">¡Pago realizado exitosamente!</span>
    </div>
    
    <footer>
        <div class="container">
            <div class="footer-content">
                <div class="logo">
                    <i class="fas fa-money-bill-wave"></i>
                    <span>Mengas Venezuela</span>
                </div>
                <div>
                    <p>&copy; 2023 Mengas Venezuela. Todos los derechos reservados.</p>
                    <p>Sistema adaptado para métodos de pago venezolanos</p>
                </div>
            </div>
        </div>
    </footer>

    <script>
        // ========== CONFIGURACIÓN INICIAL ==========
        
        // Tasas de cambio simuladas
        const exchangeRates = {
            USD: { VES: 35.5, EUR: 0.85, USD: 1 },
            VES: { USD: 0.028, EUR: 0.024, VES: 1 },
            EUR: { USD: 1.18, VES: 41.89, EUR: 1 }
        };
        
        // Monto base en USD
        const baseAmount = 50;
        let currentCurrency = 'USD';
        
        // Elementos del DOM
        const payNowBtn = document.getElementById('payNowBtn');
        const paymentModal = document.getElementById('paymentModal');
        const remindersModal = document.getElementById('remindersModal');
        const closePaymentModal = document.getElementById('closePaymentModal');
        const closeRemindersModal = document.getElementById('closeRemindersModal');
        const paymentMethodSelect = document.getElementById('paymentMethodSelect');
        const paymentForm = document.getElementById('paymentForm');
        const configureRemindersBtn = document.getElementById('configureReminders');
        const paymentMethods = document.querySelectorAll('.payment-method');
        const notification = document.getElementById('notification');
        const notificationText = document.getElementById('notificationText');
        const notificationIcon = document.getElementById('notificationIcon');
        const amountDisplay = document.getElementById('amountDisplay');
        const paymentAmount = document.getElementById('paymentAmount');
        const currencyOptions = document.querySelectorAll('.currency-option');
        const paymentHistoryBody = document.getElementById('paymentHistoryBody');
        const notificationBell = document.getElementById('notificationBell');
        
        // Inicializar detalles de pago
        const paymentDetails = {
            'card': document.getElementById('cardDetails'),
            'transfer': document.getElementById('transferDetails'),
            'zelle': document.getElementById('zelleDetails'),
            'pago-mobile': document.getElementById('pagoMobileDetails'),
            'binance': document.getElementById('binanceDetails'),
            'airtm': document.getElementById('airtmDetails')
        };
        
        // ========== FUNCIONES PRINCIPALES ==========
        
        // Función para mostrar notificaciones
        function showNotification(message, type) {
            notificationText.textContent = message;
            notification.className = `notification ${type} show`;
            
            // Cambiar ícono según el tipo
            if (type === 'success') {
                notificationIcon.className = 'fas fa-check-circle';
            } else if (type === 'error') {
                notificationIcon.className = 'fas fa-exclamation-circle';
            } else if (type === 'warning') {
                notificationIcon.className = 'fas fa-exclamation-triangle';
            }
            
            setTimeout(() => {
                notification.classList.remove('show');
            }, 5000);
        }
        
        // Función para actualizar el monto según la moneda seleccionada
        function updateAmount(currency) {
            let amount = baseAmount;
            let symbol = '$';
            
            if (currency === 'VES') {
                amount = baseAmount * exchangeRates.USD.VES;
                symbol = 'Bs. ';
                amount = amount.toLocaleString('es-VE', { maximumFractionDigits: 2 });
            } else if (currency === 'EUR') {
                amount = baseAmount * exchangeRates.USD.EUR;
                symbol = '€';
                amount = amount.toFixed(2);
            } else {
                amount = amount.toFixed(2);
            }
            
            // Actualizar display
            amountDisplay.textContent = symbol + amount;
            paymentAmount.value = symbol + amount;
            currentCurrency = currency;
        }
        
        // Función para procesar el pago
        function processPayment(method) {
            // Validar campos según el método de pago
            let isValid = true;
            let errorMessage = '';
            
            switch(method) {
                case 'card':
                    if (!document.getElementById('cardNumber').value || 
                        !document.getElementById('cardName').value || 
                        !document.getElementById('cardExpiry').value || 
                        !document.getElementById('cardCvv').value) {
                        isValid = false;
                        errorMessage = 'Por favor completa todos los campos de la tarjeta';
                    }
                    break;
                case 'pago-mobile':
                    if (!document.getElementById('phoneNumber').value || 
                        !document.getElementById('idNumber').value || 
                        !document.getElementById('bankSelect').value) {
                        isValid = false;
                        errorMessage = 'Por favor completa todos los campos de Pago Móvil';
                    }
                    break;
                case 'binance':
                    if (!document.getElementById('binanceMethod').value) {
                        isValid = false;
                        errorMessage = 'Por favor selecciona un método de Binance';
                    }
                    break;
            }
            
            if (!isValid) {
                showNotification(errorMessage, 'error');
                return false;
            }
            
            return true;
        }
        
        // Función para agregar pago al historial
        function addToPaymentHistory(amount, method) {
            const methodText = {
                'card': 'Tarjeta Internacional',
                'transfer': 'Transferencia',
                'zelle': 'Zelle',
                'pago-mobile': 'Pago Móvil',
                'binance': 'Binance',
                'airtm': 'Airtm'
            };
            
            const newRow = document.createElement('tr');
            newRow.innerHTML = `
                <td>${new Date().toLocaleDateString()}</td>
                <td>Pago de Servicios - ${new Date().toLocaleString('es-ES', { month: 'long' })}</td>
                <td>${amount}</td>
                <td>${methodText[method]}</td>
                <td><span class="status paid">Pagado</span></td>
            `;
            paymentHistoryBody.insertBefore(newRow, paymentHistoryBody.firstChild);
        }
        
        // ========== EVENT LISTENERS ==========
        
        // Abrir modal de pago
        payNowBtn.addEventListener('click', () => {
            paymentModal.style.display = 'flex';
        });
        
        // Abrir modal de recordatorios
        configureRemindersBtn.addEventListener('click', () => {
            remindersModal.style.display = 'flex';
        });
        
        // Cerrar modales
        closePaymentModal.addEventListener('click', () => {
            paymentModal.style.display = 'none';
        });
        
        closeRemindersModal.addEventListener('click', () => {
            remindersModal.style.display = 'none';
        });
        
        // Cerrar modal al hacer clic fuera
        window.addEventListener('click', (e) => {
            if (e.target === paymentModal) {
                paymentModal.style.display = 'none';
            }
            if (e.target === remindersModal) {
                remindersModal.style.display = 'none';
            }
        });
        
        // Cambiar detalles según método de pago seleccionado
        paymentMethodSelect.addEventListener('change', (e) => {
            // Ocultar todos los detalles primero
            Object.values(paymentDetails).forEach(detail => {
                if (detail) detail.style.display = 'none';
            });
            
            // Mostrar los detalles correspondientes
            if (paymentDetails[e.target.value]) {
                paymentDetails[e.target.value].style.display = 'block';
            }
        });
        
        // Seleccionar método de pago desde las tarjetas
        paymentMethods.forEach(method => {
            method.addEventListener('click', () => {
                // Quitar selección anterior
                paymentMethods.forEach(m => m.classList.remove('selected'));
                
                // Añadir selección actual
                method.classList.add('selected');
                
                // Actualizar el select
                const methodValue = method.getAttribute('data-method');
                paymentMethodSelect.value = methodValue;
                
                // Mostrar los detalles correspondientes
                Object.values(paymentDetails).forEach(detail => {
                    if (detail) detail.style.display = 'none';
                });
                
                if (paymentDetails[methodValue]) {
                    paymentDetails[methodValue].style.display = 'block';
                }
            });
        });
        
        // Cambiar moneda
        currencyOptions.forEach(option => {
            option.addEventListener('click', () => {
                // Quitar selección anterior
                currencyOptions.forEach(o => o.classList.remove('active'));
                
                // Añadir selección actual
                option.classList.add('active');
                
                // Obtener moneda seleccionada
                const currency = option.getAttribute('data-currency');
                
                // Actualizar monto
                updateAmount(currency);
            });
        });
        
        // Procesar pago
        paymentForm.addEventListener('submit', (e) => {
            e.preventDefault();
            
            // Validar que se haya seleccionado un método de pago
            if (!paymentMethodSelect.value) {
                showNotification('Por favor selecciona un método de pago', 'error');
                return;
            }
            
            // Validar campos específicos del método
            if (!processPayment(paymentMethodSelect.value)) {
                return;
            }
            
            // Simular procesamiento de pago
            showNotification('Procesando pago...', 'warning');
            
            setTimeout(() => {
                // Cerrar modal
                paymentModal.style.display = 'none';
                
                // Mostrar notificación de éxito
                showNotification('¡Pago realizado exitosamente!', 'success');
                
                // Actualizar interfaz
                setTimeout(() => {
                    // Actualizar botón de pago
                    payNowBtn.innerHTML = '<i class="fas fa-check"></i> Pagado';
                    payNowBtn.disabled = true;
                    payNowBtn.className = 'btn btn-success';
                    
                    // Actualizar estado del pago
                    document.querySelector('.payment-due').textContent = 'Pagado';
                    document.querySelector('.payment-due').className = 'payment-due';
                    
                    // Agregar al historial
                    addToPaymentHistory(paymentAmount.value, paymentMethodSelect.value);
                    
                    // Limpiar formulario
                    paymentForm.reset();
                    Object.values(paymentDetails).forEach(detail => {
                        if (detail) detail.style.display = 'none';
                    });
                    paymentMethods.forEach(m => m.classList.remove('selected'));
                    paymentMethodSelect.value = '';
                    
                }, 1000);
            }, 2000);
        });
        
        // Configurar recordatorios
        document.getElementById('remindersForm').addEventListener('submit', (e) => {
            e.preventDefault();
            remindersModal.style.display = 'none';
            showNotification('Configuración de recordatorios guardada', 'success');
        });
        
        // Notificaciones
        notificationBell.addEventListener('click', () => {
            showNotification('Tienes 2 notificaciones pendientes', 'warning');
        });
        
        // ========== INICIALIZACIÓN ==========
        
        // Ocultar todos los detalles de pago inicialmente
        Object.values(paymentDetails).forEach(detail => {
            if (detail) detail.style.display = 'none';
        });
        
        // Simular notificaciones al cargar la página
        window.addEventListener('load', () => {
            setTimeout(() => {
                showNotification('Recordatorio: Tienes un pago pendiente que vence en 3 días', 'warning');
            }, 1000);
        });
    </script>
</body>
</html>