<?php
require_once '../config/database.php';

class Notificaciones {
    private $conn;
    
    public function __construct() {
        $this->conn = Database::getConnection();
    }
    
    public function verificarPagosPorVencer() {
        // Pagos que vencen en 5 días
        $fecha_limite = date('Y-m-d', strtotime('+5 days'));
        
        $query = "SELECT p.*, u.nombre, u.email, u.telefono 
                  FROM pagos p 
                  JOIN usuarios u ON p.usuario_id = u.id 
                  WHERE p.fecha_vencimiento = ? 
                  AND p.estado = 'pendiente'";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$fecha_limite]);
        $pagos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($pagos as $pago) {
            $this->enviarNotificacion($pago, 'recordatorio');
        }
        
        return count($pagos);
    }
    
    public function verificarPagosVencidos() {
        // Pagos vencidos
        $fecha_hoy = date('Y-m-d');
        
        $query = "SELECT p.*, u.nombre, u.email, u.telefono 
                  FROM pagos p 
                  JOIN usuarios u ON p.usuario_id = u.id 
                  WHERE p.fecha_vencimiento < ? 
                  AND p.estado = 'pendiente'";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$fecha_hoy]);
        $pagos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($pagos as $pago) {
            // Actualizar estado a vencido
            $update_stmt = $this->conn->prepare("UPDATE pagos SET estado = 'vencido' WHERE id = ?");
            $update_stmt->execute([$pago['id']]);
            
            $this->enviarNotificacion($pago, 'vencido');
        }
        
        return count($pagos);
    }
    
    private function enviarNotificacion($pago, $tipo) {
        if ($tipo == 'recordatorio') {
            $asunto = "Recordatorio: Pago próximo a vencer";
            $mensaje = "Hola {$pago['nombre']}, tu pago de referencia {$pago['referencia']} vence el " . 
                      date('d/m/Y', strtotime($pago['fecha_vencimiento'])) . 
                      ". Por favor realiza el pago a tiempo.";
        } else {
            $asunto = "Alerta: Pago vencido";
            $mensaje = "Hola {$pago['nombre']}, tu pago de referencia {$pago['referencia']} venció el " . 
                      date('d/m/Y', strtotime($pago['fecha_vencimiento'])) . 
                      ". Por favor regulariza tu situación.";
        }
        
        // Guardar en base de datos
        $stmt = $this->conn->prepare(
            "INSERT INTO notificaciones (usuario_id, tipo, asunto, mensaje) VALUES (?, 'email', ?, ?)"
        );
        $stmt->execute([$pago['usuario_id'], $asunto, $mensaje]);
        
        // Enviar email (implementar con PHPMailer o similar)
        $this->enviarEmail($pago['email'], $asunto, $mensaje);
        
        // Enviar WhatsApp (integrar con API de WhatsApp Business)
        if (!empty($pago['telefono'])) {
            $this->enviarWhatsApp($pago['telefono'], $mensaje);
        }
    }
    
    private function enviarEmail($email, $asunto, $mensaje) {
        // Implementar envío de email
        // Usar PHPMailer o función mail() de PHP
        mail($email, $asunto, $mensaje);
    }
    
    private function enviarWhatsApp($telefono, $mensaje) {
        // Integrar con WhatsApp Business API
        // Esta es una implementación básica
        file_get_contents("https://api.whatsapp.com/send?phone=$telefono&text=" . urlencode($mensaje));
    }
}

// Ejecutar notificaciones
$notificaciones = new Notificaciones();
$por_vencer = $notificaciones->verificarPagosPorVencer();
$vencidos = $notificaciones->verificarPagosVencidos();

echo "Notificaciones enviadas: $por_vencer por vencer, $vencidos vencidos\n";
?>