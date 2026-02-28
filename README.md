# Doshka Backend — Symfony 7.2 REST API

A full-featured Kanban board REST API backend built with **Symfony 7.2**, **API Platform 3**, and **PostgreSQL**. Portfolio project demonstrating modern PHP backend architecture.

## Tech Stack

| Technology | Purpose |
|---|---|
| Symfony 7.2 | Core framework |
| API Platform 3 | OpenAPI/Swagger docs |
| LexikJWTAuthenticationBundle | JWT authentication |
| Doctrine ORM + PostgreSQL 16 | Database |
| Redis 7 | Cache + session |
| Symfony Messenger | Async email queue |
| Symfony Mailer | Email sending |
| Symfony Scheduler | Cron jobs (deadline reminders) |
| Docker Compose | Local development environment |
| Mailpit | Email testing (local) |

## Quick Start

### Prerequisites
- Docker & Docker Compose
- (Optional) PHP 8.3 + Composer for local dev outside Docker

### 1. Clone & Setup

```bash
git clone <repo-url> doshka-backend
cd doshka-backend
cp .env .env.local
```

### 2. Generate JWT Keys

```bash
docker compose run --rm php mkdir -p config/jwt
docker compose run --rm php openssl genrsa -out config/jwt/private.pem -passout pass:doshka_jwt_passphrase 4096
docker compose run --rm php openssl rsa -pubout -in config/jwt/private.pem -out config/jwt/public.pem -passin pass:doshka_jwt_passphrase
```

### 3. Start Services

```bash
docker compose up -d
```

### 4. Install Dependencies

```bash
docker compose exec php composer install
```

### 5. Run Migrations

```bash
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```

### 6. Start Message Worker

```bash
docker compose exec php php bin/console messenger:consume async --limit=50 &
```

## Access Points

| Service | URL |
|---|---|
| API | http://localhost/api |
| Swagger UI | http://localhost/api/docs |
| Mailpit (email) | http://localhost:8025 |
| PostgreSQL | localhost:5432 |
| Redis | localhost:6379 |

## API Endpoints

### Authentication

```
POST   /api/auth/register          Register new user
POST   /api/auth/login             Login → JWT token
GET    /api/auth/me                Get current user profile
PATCH  /api/auth/me                Update profile
```

**Register payload:**
```json
{
  "email": "john@example.com",
  "username": "john",
  "password": "secretpassword"
}
```

**Login response:**
```json
{
  "token": "eyJ...",
  "user": { "id": 1, "email": "...", "username": "..." }
}
```

All subsequent requests require: `Authorization: Bearer <token>`

---

### Boards

```
GET    /api/boards                 List my boards
POST   /api/boards                 Create board
GET    /api/boards/{id}            Board details + lists + cards
PATCH  /api/boards/{id}            Update board
DELETE /api/boards/{id}            Delete board (owner only)
GET    /api/boards/{id}/activity   Activity log
```

**Board payload:**
```json
{
  "title": "My Project",
  "description": "Project description",
  "color": "#0079BF"
}
```

---

### Board Members

```
GET    /api/boards/{id}/members                    List members
POST   /api/boards/{id}/members                    Invite by email
PATCH  /api/boards/{id}/members/{userId}           Change role
DELETE /api/boards/{id}/members/{userId}           Remove member
```

**Invite payload:** `{ "email": "user@example.com", "role": "member" }`
**Roles:** `owner` | `admin` | `member`

---

### Lists

```
GET    /api/boards/{id}/lists      List all lists
POST   /api/boards/{id}/lists      Create list
PATCH  /api/lists/{id}             Update list
DELETE /api/lists/{id}             Delete list
POST   /api/lists/{id}/reorder     Change position
```

**Reorder payload:** `{ "position": 2 }`

---

### Cards

```
GET    /api/lists/{id}/cards       List cards in list
POST   /api/lists/{id}/cards       Create card
GET    /api/cards/{id}             Card details + comments
PATCH  /api/cards/{id}             Update card (title, desc, dueDate, listId)
DELETE /api/cards/{id}             Delete card
POST   /api/cards/{id}/reorder     Reorder within list
POST   /api/cards/{id}/move        Move to another list
```

**Card payload:**
```json
{
  "title": "Implement feature X",
  "description": "Details...",
  "dueDate": "2026-03-15T18:00:00+00:00"
}
```

**Move payload:** `{ "listId": 5, "position": 0 }`

---

### Card Members (Assignees)

```
GET    /api/cards/{id}/members                     List assignees
POST   /api/cards/{id}/members                     Assign user { userId: 3 }
DELETE /api/cards/{cardId}/members/{userId}        Unassign user
```

---

### Labels

```
GET    /api/boards/{id}/labels                     List board labels
POST   /api/boards/{id}/labels                     Create label
PATCH  /api/labels/{id}                            Update label
DELETE /api/labels/{id}                            Delete label
POST   /api/cards/{id}/labels                      Attach label { labelId: 2 }
DELETE /api/cards/{cardId}/labels/{labelId}        Detach label
```

**Label payload:** `{ "name": "Bug", "color": "#EB5A46" }`

---

### Comments

```
GET    /api/cards/{id}/comments    List comments
POST   /api/cards/{id}/comments    Create comment { content: "..." }
PATCH  /api/comments/{id}          Edit comment (author only)
DELETE /api/comments/{id}          Delete comment (author or admin)
```

---

## Permissions Model

| Action | owner | admin | member |
|---|---|---|---|
| View board | ✅ | ✅ | ✅ |
| Create/edit cards | ✅ | ✅ | ✅ |
| Invite members | ✅ | ✅ | ❌ |
| Change member roles | ✅ | ❌ | ❌ |
| Delete board | ✅ | ❌ | ❌ |
| Edit/delete any comment | ✅ | ✅ | ❌ |

---

## Email Notifications

Emails are sent asynchronously via Symfony Messenger + Redis queue:

| Event | Recipients |
|---|---|
| Invited to board | Invited user |
| Assigned to card | Assigned user |
| New comment on card | All card assignees (except commenter) |
| Deadline tomorrow | All card assignees |

Deadline reminders run via **Symfony Scheduler** at **08:00 UTC daily**.

Start the worker: `php bin/console messenger:consume async`

---

## Running Tests

```bash
# Setup test database
docker compose exec php php bin/console doctrine:database:create --env=test
docker compose exec php php bin/console doctrine:migrations:migrate --env=test --no-interaction

# Run tests
docker compose exec php php bin/phpunit
```

---

## Development Commands

```bash
# Clear cache
docker compose exec php php bin/console cache:clear

# Create migration after entity changes
docker compose exec php php bin/console doctrine:migrations:diff

# Run migrations
docker compose exec php php bin/console doctrine:migrations:migrate

# Consume messages (email queue)
docker compose exec php php bin/console messenger:consume async -vv

# View failed messages
docker compose exec php php bin/console messenger:failed:show

# Retry failed messages
docker compose exec php php bin/console messenger:failed:retry
```

---

## Project Structure

```
src/
├── Controller/          # REST API controllers
│   ├── AuthController.php
│   ├── BoardController.php  (boards + board members)
│   ├── ListController.php
│   ├── CardController.php   (cards + card members)
│   ├── LabelController.php
│   └── CommentController.php
├── Entity/              # Doctrine ORM entities
├── Repository/          # Database query repositories
├── Security/            # Voters (authorization)
│   ├── BoardVoter.php
│   └── CommentVoter.php
├── Service/
│   └── ActivityLogger.php   # Logs board activity
├── Message/             # Messenger message DTOs
├── MessageHandler/      # Messenger handlers (email sending)
└── Scheduler/           # Deadline reminder scheduler
```

---

## Environment Variables

| Variable | Default | Description |
|---|---|---|
| `DATABASE_URL` | `postgresql://doshka:...@postgres:5432/doshka` | PostgreSQL connection |
| `JWT_PASSPHRASE` | `doshka_jwt_passphrase` | JWT key passphrase |
| `JWT_TOKEN_TTL` | `3600` | Token lifetime (seconds) |
| `MESSENGER_TRANSPORT_DSN` | `redis://redis:6379/messages` | Message queue |
| `MAILER_DSN` | `smtp://mailpit:1025` | Email server |
| `REDIS_URL` | `redis://redis:6379` | Redis for cache |
