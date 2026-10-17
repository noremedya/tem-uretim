-- Uygulama veritabanı kullanıcısı (CLAUDE.md bölüm 11, Aşama 10).
-- Uygulama tablo sahibi OLMAYAN bu kullanıcıyla bağlanır: yalnızca veri okuma/yazma ve sayaç
-- yetkileri vardır; tablo yapısını değiştiremez, trigger kapatamaz (ALTER TABLE ... DISABLE TRIGGER),
-- TRUNCATE ve DROP yapamaz. Migration'lar tablo sahibiyle çalışır.
--
-- Tablo sahibi kullanıcıyla, psql ile çalıştırılır; tekrar çalıştırılabilir (deploy.sh, restore.sh,
-- db-migrate.sh her seferinde çalıştırır). Değişkenler:
--   psql -v app_user=uretim_app -v owner_user=uretim -v db_name=uretim -f app-role.sql
--   şifre APP_DB_PASSWORD ortam değişkeninden okunur (komut satırında görünmesin diye).

\set ON_ERROR_STOP on
\getenv app_password APP_DB_PASSWORD

-- Şifre tanımsız veya boşsa hata ile durulur (ON_ERROR_STOP; psql sıfırdan farklı kodla çıkar).
\if :{?app_password}
    SELECT :'app_password' <> '' AS has_password \gset
\else
    \set has_password false
\endif
\if :has_password
\else
    DO $$ BEGIN RAISE EXCEPTION 'APP_DB_PASSWORD tanımsız veya boş.'; END $$;
\endif

SELECT format('CREATE ROLE %I LOGIN', :'app_user')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'app_user') \gexec

ALTER ROLE :"app_user" WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS
    PASSWORD :'app_password';

GRANT CONNECT, TEMPORARY ON DATABASE :"db_name" TO :"app_user";

-- public şemasında nesne oluşturamaz (PostgreSQL 15+ varsayılanı; açıkça da kapatılır).
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
REVOKE CREATE ON SCHEMA public FROM :"app_user";
GRANT USAGE ON SCHEMA public TO :"app_user";

-- Mevcut tablolar ve sayaçlar. TRUNCATE, REFERENCES ve TRIGGER yetkileri verilmez.
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO :"app_user";
GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO :"app_user";
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA public TO :"app_user";

-- Tablo sahibinin ileride (yeni migration'larla) oluşturacağı nesneler.
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner_user" IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO :"app_user";
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner_user" IN SCHEMA public
    GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO :"app_user";
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner_user" IN SCHEMA public
    GRANT EXECUTE ON FUNCTIONS TO :"app_user";
