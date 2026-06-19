-- Asigna las conversaciones sin empresa_id a la primera empresa con WhatsApp activo.
-- Estas son filas pre-multitenancy creadas antes de que el webhook guardara empresa_id.
UPDATE whatsapp_conversations
SET empresa_id = (
    SELECT MIN(empresa_id)
    FROM empresa_whatsapp_config
    WHERE activo = 1
)
WHERE empresa_id IS NULL;

-- Verificación: no debe quedar ninguna fila con empresa_id NULL
-- SELECT COUNT(*) FROM whatsapp_conversations WHERE empresa_id IS NULL;
