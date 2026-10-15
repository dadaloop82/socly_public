-- RUNTS import: riclassifica i documenti gia' importati (atto costitutivo, provvedimenti)
-- mantenendo intatti marcatore e nome file nella sintesi (servono alla deduplica e al re-download).

UPDATE association_documents
SET category = 'constitutive_act'
WHERE category = 'other'
  AND summary LIKE '%Marcatore: RUNTS:%'
  AND (title LIKE 'ATTO COSTITUTIVO%' OR title LIKE '%ATTO COSTITUTIVO%' OR title LIKE '%COSTITUZIONE%');

UPDATE association_documents
SET category = 'runts_cert'
WHERE category = 'other'
  AND summary LIKE '%Marcatore: RUNTS:%'
  AND (
      title LIKE '%PROVVEDIMENTO%'
      OR title LIKE '%DECRETO%'
      OR title LIKE '%ISCRIZIONE%'
      OR title LIKE '%RUNTS%'
  );
