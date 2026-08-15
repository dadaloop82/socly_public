ALTER TABLE treasury_movements
    ADD FULLTEXT INDEX ft_treasury_search (description, category, payment_method);
