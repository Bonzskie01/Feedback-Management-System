BEGIN;

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

CREATE INDEX IF NOT EXISTS idx_audit_logs_created_at ON audit_logs(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_logs_admin_id ON audit_logs(admin_id);

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGSERIAL PRIMARY KEY,
    identity_hash CHAR(64) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempt_type VARCHAR(20) NOT NULL CHECK (attempt_type IN ('password', '2fa')),
    succeeded BOOLEAN NOT NULL DEFAULT FALSE,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_login_attempts_lookup
    ON login_attempts(identity_hash, ip_address, attempt_type, attempted_at DESC);
CREATE INDEX IF NOT EXISTS idx_login_attempts_cleanup ON login_attempts(attempted_at);

CREATE TABLE IF NOT EXISTS vote_abstentions (
    id BIGSERIAL PRIMARY KEY,
    election_id INTEGER NOT NULL REFERENCES elections(id) ON DELETE CASCADE,
    position_id INTEGER NOT NULL REFERENCES positions(id) ON DELETE CASCADE,
    abstained_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_vote_abstentions_election_position
    ON vote_abstentions(election_id, position_id);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conrelid = 'vote_abstentions'::regclass
           AND conname = 'vote_abstentions_election_fk'
    ) THEN
        ALTER TABLE vote_abstentions
            ADD CONSTRAINT vote_abstentions_election_fk
            FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE CASCADE NOT VALID;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conrelid = 'vote_abstentions'::regclass
           AND conname = 'vote_abstentions_position_fk'
    ) THEN
        ALTER TABLE vote_abstentions
            ADD CONSTRAINT vote_abstentions_position_fk
            FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE NOT VALID;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conrelid = 'vote_abstentions'::regclass
           AND conname = 'vote_abstentions_ids_required'
    ) THEN
        ALTER TABLE vote_abstentions
            ADD CONSTRAINT vote_abstentions_ids_required
            CHECK (election_id IS NOT NULL AND position_id IS NOT NULL) NOT VALID;
    END IF;
END $$;

CREATE UNIQUE INDEX IF NOT EXISTS idx_voter_turnout_unique_voter_election
    ON voter_turnout(voter_id, election_id);

COMMIT;
