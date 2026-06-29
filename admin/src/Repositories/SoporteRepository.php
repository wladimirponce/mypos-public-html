<?php
declare(strict_types=1);

namespace App\Repositories;

class SoporteRepository extends BaseRepository
{
    public function getTickets(int $empresaId = 0, string $estado = 'todos'): array
    {
        $sql = "SELECT t.*, e.razon_social as empresa_nombre, u.nombre as usuario_nombre 
                FROM saas_ticket_soporte t
                JOIN sii_empresa e ON t.empresa_id = e.id
                LEFT JOIN sii_usuario u ON t.usuario_id = u.id
                WHERE 1=1 ";
        
        $params = [];
        if ($empresaId > 0) {
            $sql .= " AND t.empresa_id = ?";
            $params[] = $empresaId;
        }
        if ($estado !== 'todos') {
            $sql .= " AND t.estado = ?";
            $params[] = $estado;
        }
        
        $sql .= " ORDER BY t.creado_en DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    public function getTicketConMensajes(int $ticketId): ?array
    {
        $sql = "SELECT * FROM saas_ticket_soporte WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch();
        
        if (!$ticket) return null;

        $sqlMsgs = "SELECT m.*, u.nombre as usuario_nombre 
                    FROM saas_ticket_mensaje m
                    LEFT JOIN sii_usuario u ON m.usuario_id = u.id
                    WHERE m.ticket_id = ? 
                    ORDER BY m.creado_en ASC";
        $stmtMsgs = $this->db->prepare($sqlMsgs);
        $stmtMsgs->execute([$ticketId]);
        $ticket['mensajes'] = $stmtMsgs->fetchAll() ?: [];
        
        return $ticket;
    }

    public function crearTicket(int $empresaId, ?int $usuarioId, string $asunto, string $prioridad = 'media', string $mensaje = ''): int
    {
        $sql = "INSERT INTO saas_ticket_soporte (empresa_id, usuario_id, asunto, prioridad)
                VALUES (?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$empresaId, $usuarioId, $asunto, $prioridad]);
        $ticketId = (int)$this->db->lastInsertId();

        if ($mensaje !== '') {
            $this->agregarMensaje($ticketId, $usuarioId, $mensaje);
        }
        
        return $ticketId;
    }

    public function agregarMensaje(int $ticketId, ?int $usuarioId, string $mensaje, ?string $adjuntoUrl = null): int
    {
        $sql = "INSERT INTO saas_ticket_mensaje (ticket_id, usuario_id, mensaje, adjunto_url)
                VALUES (?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$ticketId, $usuarioId, $mensaje, $adjuntoUrl]);
        
        // Si el mensaje es de la central (usuarioId nulo), cambiamos estado a 'resuelto' o 'en_progreso'
        $nuevoEstado = $usuarioId === null ? 'resuelto' : 'abierto';
        $sqlUpdate = "UPDATE saas_ticket_soporte SET estado = ?, actualizado_en = NOW() WHERE id = ?";
        $stmtUpdate = $this->db->prepare($sqlUpdate);
        $stmtUpdate->execute([$nuevoEstado, $ticketId]);

        return (int)$this->db->lastInsertId();
    }
}
