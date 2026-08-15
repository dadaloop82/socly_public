ALTER TABLE deadline_items
    ADD FULLTEXT INDEX ft_deadlines_search (title, notes, category);
