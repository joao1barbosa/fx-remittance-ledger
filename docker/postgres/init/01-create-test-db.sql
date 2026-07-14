-- Runs once on first container init (empty data dir).
-- Creates the isolated test database used by the Pest/PHPUnit suite,
-- so tests hit real Postgres (jsonb, numeric) without touching dev data.
CREATE DATABASE fx_remittance_test;
