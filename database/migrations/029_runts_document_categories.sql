-- RUNTS import: reclassifies the documents already imported (deed of incorporation, provisions) keeping the
-- marker and file name intact in the summary (they are used for deduplication and re-download).

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
