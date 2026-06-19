-- Etapas de pipeline personalizables por empresa
CREATE TABLE IF NOT EXISTS crm_etapas (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id    INT NOT NULL,
  nombre        VARCHAR(80)  NOT NULL,
  slug          VARCHAR(50)  NOT NULL,
  color         VARCHAR(20)  NOT NULL DEFAULT '#6366f1',
  orden         INT          NOT NULL DEFAULT 0,
  es_convertido TINYINT(1)   NOT NULL DEFAULT 0,
  es_descarte   TINYINT(1)   NOT NULL DEFAULT 0,
  activo        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX   idx_ce_empresa (empresa_id),
  UNIQUE KEY uq_ce_slug (empresa_id, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sembrar etapas por defecto para empresas con conversaciones existentes
INSERT IGNORE INTO crm_etapas (empresa_id, nombre, slug, color, orden, es_convertido, es_descarte)
SELECT DISTINCT empresa_id, 'Nuevo',      'NUEVO',      '#3b82f6', 1, 0, 0 FROM whatsapp_conversations WHERE empresa_id IS NOT NULL;
INSERT IGNORE INTO crm_etapas (empresa_id, nombre, slug, color, orden, es_convertido, es_descarte)
SELECT DISTINCT empresa_id, 'Contactado', 'CONTACTADO', '#f59e0b', 2, 0, 0 FROM whatsapp_conversations WHERE empresa_id IS NOT NULL;
INSERT IGNORE INTO crm_etapas (empresa_id, nombre, slug, color, orden, es_convertido, es_descarte)
SELECT DISTINCT empresa_id, 'Calificado', 'CALIFICADO', '#8b5cf6', 3, 0, 0 FROM whatsapp_conversations WHERE empresa_id IS NOT NULL;
INSERT IGNORE INTO crm_etapas (empresa_id, nombre, slug, color, orden, es_convertido, es_descarte)
SELECT DISTINCT empresa_id, 'Convertido', 'CONVERTIDO', '#10b981', 4, 1, 0 FROM whatsapp_conversations WHERE empresa_id IS NOT NULL;
INSERT IGNORE INTO crm_etapas (empresa_id, nombre, slug, color, orden, es_convertido, es_descarte)
SELECT DISTINCT empresa_id, 'Descartado', 'DESCARTADO', '#ef4444', 5, 0, 1 FROM whatsapp_conversations WHERE empresa_id IS NOT NULL;

-- También para empresas con WhatsApp config pero sin conversaciones aún
INSERT IGNORE INTO crm_etapas (empresa_id, nombre, slug, color, orden, es_convertido, es_descarte)
SELECT DISTINCT empresa_id, 'Nuevo',      'NUEVO',      '#3b82f6', 1, 0, 0 FROM empresa_whatsapp_config WHERE activo = 1;
INSERT IGNORE INTO crm_etapas (empresa_id, nombre, slug, color, orden, es_convertido, es_descarte)
SELECT DISTINCT empresa_id, 'Contactado', 'CONTACTADO', '#f59e0b', 2, 0, 0 FROM empresa_whatsapp_config WHERE activo = 1;
INSERT IGNORE INTO crm_etapas (empresa_id, nombre, slug, color, orden, es_convertido, es_descarte)
SELECT DISTINCT empresa_id, 'Calificado', 'CALIFICADO', '#8b5cf6', 3, 0, 0 FROM empresa_whatsapp_config WHERE activo = 1;
INSERT IGNORE INTO crm_etapas (empresa_id, nombre, slug, color, orden, es_convertido, es_descarte)
SELECT DISTINCT empresa_id, 'Convertido', 'CONVERTIDO', '#10b981', 4, 1, 0 FROM empresa_whatsapp_config WHERE activo = 1;
INSERT IGNORE INTO crm_etapas (empresa_id, nombre, slug, color, orden, es_convertido, es_descarte)
SELECT DISTINCT empresa_id, 'Descartado', 'DESCARTADO', '#ef4444', 5, 0, 1 FROM empresa_whatsapp_config WHERE activo = 1;

-- Cambiar ENUM a VARCHAR para permitir etapas personalizadas
ALTER TABLE whatsapp_conversations
  MODIFY COLUMN estado_lead VARCHAR(50) NOT NULL DEFAULT 'NUEVO';
