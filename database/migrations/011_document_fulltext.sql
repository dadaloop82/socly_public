ALTER TABLE association_documents
    ADD FULLTEXT INDEX ft_documents_search (title, summary, category, language);
