BEGIN;

-- Core tables required by a fresh Feedback Management System installation.
-- These statements are safe to run against an upgraded voting-system database:
-- existing compatible tables and records are preserved.
CREATE TABLE IF NOT EXISTS admins (
    id SERIAL PRIMARY KEY,
    username VARCHAR(80) NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    full_name VARCHAR(180) NOT NULL,
    totp_secret TEXT,
    totp_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    backup_codes TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL DEFAULT '',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGSERIAL PRIMARY KEY,
    admin_id INTEGER REFERENCES admins(id) ON DELETE SET NULL,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(100),
    target_id VARCHAR(100),
    ip_address VARCHAR(45),
    user_agent TEXT,
    details JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGSERIAL PRIMARY KEY,
    identity_hash CHAR(64) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempt_type VARCHAR(20) NOT NULL CHECK (attempt_type IN ('password', '2fa')),
    succeeded BOOLEAN NOT NULL DEFAULT FALSE,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_audit_logs_created_at ON audit_logs(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_logs_admin_id ON audit_logs(admin_id);
CREATE INDEX IF NOT EXISTS idx_login_attempts_lookup ON login_attempts(identity_hash, ip_address, attempt_type, attempted_at DESC);
CREATE INDEX IF NOT EXISTS idx_login_attempts_cleanup ON login_attempts(attempted_at);

CREATE TABLE IF NOT EXISTS feedback_campaigns (
    id SERIAL PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft', 'active', 'paused', 'closed')),
    start_at TIMESTAMP,
    end_at TIMESTAMP,
    allow_anonymous BOOLEAN NOT NULL DEFAULT FALSE,
    created_by INTEGER REFERENCES admins(id) ON DELETE SET NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (end_at IS NULL OR start_at IS NULL OR end_at > start_at)
);

CREATE TABLE IF NOT EXISTS feedback_questions (
    id SERIAL PRIMARY KEY,
    campaign_id INTEGER NOT NULL REFERENCES feedback_campaigns(id) ON DELETE CASCADE,
    prompt TEXT NOT NULL,
    question_type VARCHAR(24) NOT NULL
        CHECK (question_type IN ('rating', 'single_choice', 'multiple_choice', 'text')),
    options JSONB NOT NULL DEFAULT '[]'::jsonb,
    is_required BOOLEAN NOT NULL DEFAULT TRUE,
    display_order INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS feedback_participants (
    id SERIAL PRIMARY KEY,
    campaign_id INTEGER NOT NULL REFERENCES feedback_campaigns(id) ON DELETE CASCADE,
    participant_code VARCHAR(80) NOT NULL,
    full_name VARCHAR(180) NOT NULL,
    group_name VARCHAR(120) NOT NULL DEFAULT '',
    password_hash TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active'
        CHECK (status IN ('active', 'responded', 'disabled')),
    responded_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (campaign_id, participant_code)
);

CREATE TABLE IF NOT EXISTS feedback_submissions (
    id BIGSERIAL PRIMARY KEY,
    campaign_id INTEGER NOT NULL REFERENCES feedback_campaigns(id) ON DELETE CASCADE,
    participant_id INTEGER REFERENCES feedback_participants(id) ON DELETE SET NULL,
    reference_code VARCHAR(32) NOT NULL UNIQUE,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_hash CHAR(64),
    UNIQUE (campaign_id, participant_id)
);

CREATE TABLE IF NOT EXISTS feedback_answers (
    id BIGSERIAL PRIMARY KEY,
    submission_id BIGINT NOT NULL REFERENCES feedback_submissions(id) ON DELETE CASCADE,
    question_id INTEGER NOT NULL REFERENCES feedback_questions(id) ON DELETE CASCADE,
    rating_value SMALLINT CHECK (rating_value BETWEEN 1 AND 5),
    text_value TEXT,
    selected_options JSONB NOT NULL DEFAULT '[]'::jsonb,
    UNIQUE (submission_id, question_id)
);

CREATE INDEX IF NOT EXISTS idx_feedback_campaigns_status ON feedback_campaigns(status);
CREATE INDEX IF NOT EXISTS idx_feedback_questions_campaign ON feedback_questions(campaign_id, display_order, id);
CREATE INDEX IF NOT EXISTS idx_feedback_participants_campaign ON feedback_participants(campaign_id, status);
CREATE UNIQUE INDEX IF NOT EXISTS uq_feedback_participant_code_ci ON feedback_participants(lower(participant_code));
CREATE INDEX IF NOT EXISTS idx_feedback_submissions_campaign ON feedback_submissions(campaign_id, submitted_at DESC);
CREATE INDEX IF NOT EXISTS idx_feedback_answers_question ON feedback_answers(question_id);

INSERT INTO settings (setting_key, setting_value)
VALUES ('site_name', 'Feedback Management System')
ON CONFLICT (setting_key) DO UPDATE
SET setting_value = CASE
    WHEN settings.setting_value ILIKE '%voting%' OR settings.setting_value ILIKE '%election%'
        THEN EXCLUDED.setting_value
    ELSE settings.setting_value
END,
updated_at = CURRENT_TIMESTAMP;

INSERT INTO settings (setting_key, setting_value) VALUES
    ('school_name', 'Your Organization'),
    ('school_address', ''),
    ('logo_path', 'assets/images/logo_1784518396.png'),
    ('favicon_path', 'assets/images/favicon_1784518396.png'),
    ('background_path', 'assets/images/bg_1784518396.jpg')
ON CONFLICT (setting_key) DO NOTHING;

COMMIT;
