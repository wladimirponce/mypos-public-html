-- Aislamiento estricto de certificados y consumo de folios por ambiente.

ALTER TABLE sii_certificado
    ADD COLUMN IF NOT EXISTS ambiente_sii ENUM('certificacion','produccion')
        NOT NULL DEFAULT 'certificacion' AFTER vigencia_hasta,
    ADD INDEX IF NOT EXISTS idx_cert_empresa_ambiente
        (empresa_id, ambiente_sii, activo);

ALTER TABLE sii_folio_consumo
    ADD COLUMN IF NOT EXISTS ambiente ENUM('certificacion','produccion')
        NOT NULL DEFAULT 'certificacion' AFTER folio;

UPDATE sii_certificado c
JOIN sii_empresa e ON e.id = c.empresa_id
SET c.ambiente_sii = e.ambiente_default;

UPDATE sii_folio_consumo f
JOIN sii_caf c ON c.id = f.caf_id
SET f.ambiente = c.ambiente_sii;

ALTER TABLE sii_folio_consumo
    ADD INDEX IF NOT EXISTS idx_folio_empresa (empresa_id),
    DROP INDEX uq_folio_empresa,
    ADD UNIQUE KEY uq_folio_empresa_ambiente (empresa_id, tipo_dte, folio, ambiente);
