<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260228000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: users, boards, board_members, board_lists, cards, card_members, labels, card_labels, comments, activities';
    }

    public function up(Schema $schema): void
    {
        // Users
        $this->addSql('CREATE TABLE users (
            id SERIAL PRIMARY KEY,
            email VARCHAR(180) NOT NULL UNIQUE,
            username VARCHAR(50) NOT NULL UNIQUE,
            roles JSON NOT NULL DEFAULT \'[]\',
            password VARCHAR(255) NOT NULL,
            avatar_url VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
        )');
        $this->addSql('COMMENT ON COLUMN users.created_at IS \'(DC2Type:datetime_immutable)\'');

        // Boards
        $this->addSql('CREATE TABLE boards (
            id SERIAL PRIMARY KEY,
            owner_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            color VARCHAR(7) DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            CONSTRAINT fk_boards_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
        )');
        $this->addSql('COMMENT ON COLUMN boards.created_at IS \'(DC2Type:datetime_immutable)\'');

        // Board Members
        $this->addSql('CREATE TABLE board_members (
            id SERIAL PRIMARY KEY,
            board_id INT NOT NULL,
            user_id INT NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT \'member\',
            CONSTRAINT fk_bm_board FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE,
            CONSTRAINT fk_bm_user  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
            CONSTRAINT unique_board_member UNIQUE (board_id, user_id)
        )');

        // Board Lists
        $this->addSql('CREATE TABLE board_lists (
            id SERIAL PRIMARY KEY,
            board_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            position INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            CONSTRAINT fk_bl_board FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
        )');
        $this->addSql('COMMENT ON COLUMN board_lists.created_at IS \'(DC2Type:datetime_immutable)\'');

        // Cards
        $this->addSql('CREATE TABLE cards (
            id SERIAL PRIMARY KEY,
            list_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            position INT NOT NULL DEFAULT 0,
            due_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            CONSTRAINT fk_cards_list FOREIGN KEY (list_id) REFERENCES board_lists(id) ON DELETE CASCADE
        )');
        $this->addSql('COMMENT ON COLUMN cards.due_date IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN cards.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN cards.updated_at IS \'(DC2Type:datetime_immutable)\'');

        // Card Members (assignees)
        $this->addSql('CREATE TABLE card_members (
            id SERIAL PRIMARY KEY,
            card_id INT NOT NULL,
            user_id INT NOT NULL,
            CONSTRAINT fk_cm_card FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE,
            CONSTRAINT fk_cm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT unique_card_member UNIQUE (card_id, user_id)
        )');

        // Labels
        $this->addSql('CREATE TABLE labels (
            id SERIAL PRIMARY KEY,
            board_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            color VARCHAR(7) NOT NULL,
            CONSTRAINT fk_labels_board FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
        )');

        // Card Labels
        $this->addSql('CREATE TABLE card_labels (
            id SERIAL PRIMARY KEY,
            card_id INT NOT NULL,
            label_id INT NOT NULL,
            CONSTRAINT fk_cl_card  FOREIGN KEY (card_id)  REFERENCES cards(id)  ON DELETE CASCADE,
            CONSTRAINT fk_cl_label FOREIGN KEY (label_id) REFERENCES labels(id) ON DELETE CASCADE,
            CONSTRAINT unique_card_label UNIQUE (card_id, label_id)
        )');

        // Comments
        $this->addSql('CREATE TABLE comments (
            id SERIAL PRIMARY KEY,
            card_id INT NOT NULL,
            author_id INT NOT NULL,
            content TEXT NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            CONSTRAINT fk_comments_card   FOREIGN KEY (card_id)   REFERENCES cards(id)  ON DELETE CASCADE,
            CONSTRAINT fk_comments_author FOREIGN KEY (author_id) REFERENCES users(id)
        )');
        $this->addSql('COMMENT ON COLUMN comments.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN comments.updated_at IS \'(DC2Type:datetime_immutable)\'');

        // Activities
        $this->addSql('CREATE TABLE activities (
            id SERIAL PRIMARY KEY,
            board_id INT NOT NULL,
            card_id INT DEFAULT NULL,
            user_id INT NOT NULL,
            action VARCHAR(100) NOT NULL,
            payload JSON DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            CONSTRAINT fk_activities_board FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE,
            CONSTRAINT fk_activities_card  FOREIGN KEY (card_id)  REFERENCES cards(id)  ON DELETE SET NULL,
            CONSTRAINT fk_activities_user  FOREIGN KEY (user_id)  REFERENCES users(id)
        )');
        $this->addSql('COMMENT ON COLUMN activities.created_at IS \'(DC2Type:datetime_immutable)\'');

        // Indexes for performance
        $this->addSql('CREATE INDEX idx_board_members_board ON board_members (board_id)');
        $this->addSql('CREATE INDEX idx_board_lists_board ON board_lists (board_id, position)');
        $this->addSql('CREATE INDEX idx_cards_list ON cards (list_id, position)');
        $this->addSql('CREATE INDEX idx_activities_board ON activities (board_id, created_at DESC)');
        $this->addSql('CREATE INDEX idx_cards_due_date ON cards (due_date) WHERE due_date IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS activities');
        $this->addSql('DROP TABLE IF EXISTS comments');
        $this->addSql('DROP TABLE IF EXISTS card_labels');
        $this->addSql('DROP TABLE IF EXISTS labels');
        $this->addSql('DROP TABLE IF EXISTS card_members');
        $this->addSql('DROP TABLE IF EXISTS cards');
        $this->addSql('DROP TABLE IF EXISTS board_lists');
        $this->addSql('DROP TABLE IF EXISTS board_members');
        $this->addSql('DROP TABLE IF EXISTS boards');
        $this->addSql('DROP TABLE IF EXISTS users');
    }
}
