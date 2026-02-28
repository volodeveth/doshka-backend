# Doshka Backend — Claude Context

## Проект
Kanban board REST API (Trello-like). Portfolio project.
**GitHub:** https://github.com/volodeveth/doshka-backend

## Tech Stack
- Symfony 7.2 + API Platform 3
- LexikJWTAuthenticationBundle (JWT auth)
- Doctrine ORM + PostgreSQL 16
- Redis 7 (cache + message queue)
- Symfony Messenger (async emails)
- Symfony Scheduler (deadline reminders, cron 08:00 UTC)
- Docker Compose: php, nginx, postgres, redis, mailpit

## Статус
Код повністю реалізований. Після `wsl --update` + перезавантаження потрібно:

```bash
docker compose up -d
docker compose exec php composer install
make jwt-keys
make migrate
```

## Структура src/
- `Controller/` — Auth, Board (+members), List, Card (+members), Label, Comment
- `Entity/` — User, Board, BoardMember, BoardList, Card, CardMember, Label, CardLabel, Comment, Activity
- `Repository/` — репозиторії для кожної entity
- `Security/` — BoardVoter, CommentVoter
- `Service/ActivityLogger.php` — логування дій на дошці
- `Message/` + `MessageHandler/` — email-повідомлення через чергу
- `Scheduler/DeadlineReminderTask.php` — щоденні нагадування про дедлайни

## Ключові рішення
- `POST /api/auth/login` — через `json_login` у security.yaml, не контролер
- `BoardVoter` — перевіряє роль через таблицю `board_members` (owner/admin/member)
- `ActivityLogger` — сервіс, викликається явно в контролерах (не EventListener)
- Всі відповіді — ручна JSON-нормалізація, без API Platform ресурсів

## URL
- API: http://localhost/api
- Swagger: http://localhost/api/docs
- Mailpit: http://localhost:8025
