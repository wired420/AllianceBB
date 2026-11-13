# Two test sites on one VM

This kit runs two AllianceBB sites, **alpha** and **beta**, on one machine, so you can test sharing sections between them. Each site gets its own database, its own background worker and its own HTTPS certificate.

## What you need

- A Linux VM with at least 2 GB of RAM and 10 GB of free disk, reachable from the internet on ports 80 and 443.
- Docker Engine with the Compose plugin (`docker compose version` should work). On Debian or Ubuntu: `curl -fsSL https://get.docker.com | sh`.
- Two subdomains, for example `alpha.example.com` and `beta.example.com`, each with a DNS **A record** pointing at the VM's public IP. Create these first, because the HTTPS certificates are issued when the sites start.

## Start the sites

```sh
git clone -b claude/project-thread-oaoc1l https://github.com/wired420/alliancebb.git
cd alliancebb/deploy/two-sites
./setup.sh alpha.example.com beta.example.com you@example.com
docker compose up -d --build
```

`setup.sh` writes `.env` with random passwords and prints an **install token**. Keep that token for the next step.

The first build takes a few minutes. When it is done, `docker compose ps` should list `proxy`, `db`, `alpha`, `alpha-worker`, `beta` and `beta-worker` as running.

## Install each site

1. Open `https://alpha.example.com`. You'll see the installer.
2. Enter the install token, a site name (for example `Alpha Forum`), and an admin username, email and password.
3. Repeat on `https://beta.example.com` with a different site name (for example `Beta Board`).

Users from each site then show on the other as `name@AlphaForum` and `name@BetaBoard`.

## Test checklist

Do these in order. Each step says what you should see.

### Set up sharing

1. **Alpha:** Under Admin › Forums, create a category and a forum inside it, for example "General" › "Chat". Add a sub-forum to "Chat" too.
2. **Alpha:** Under Admin › Settings, set sharing to **Private**. Then under Admin › Sharing, click **Make shareable** next to "General".
3. **Beta:** Under Admin › Sharing, copy **Your webhook address**.
4. **Alpha:** Under Admin › Sharing, in **Add a site**, enter beta's name, address (`https://beta.example.com`) and the webhook address from step 3. Click **Add site and create key**, choose "General", click **Get connection code**, then **Copy connection code**.
5. **Beta:** Under Admin › Sharing › **Clone a section from another site**, paste the code, choose where to put it, and click **Clone**.
   - You should see "General", "Chat" and the sub-forum appear on beta's index, with the same names, marked as cloned from Alpha Forum.

### Posts flow both ways

6. **Alpha:** Start a topic in "Chat". Within a few seconds it appears in beta's "Chat", by `admin@AlphaForum`.
7. **Beta:** Open that topic and reply. The reply shows on beta right away as "sending", then as normal once alpha accepts it. It appears on alpha by `admin@BetaBoard`.
8. **Beta:** Start a new topic in the cloned "Chat". It appears on alpha too.
9. Open the same topic on both sites in two browser windows. Reply on either one. The reply appears in the other window **without reloading**.

### Edits, moderation and structure

10. **Alpha:** Edit your post. The change shows on beta.
11. **Beta:** Try to edit alpha's post. There is no Edit link, because beta can't change another site's content.
12. **Alpha:** Lock the topic. Beta shows it as locked, and a reply from beta is refused with a message.
13. **Alpha:** Rename "Chat" to "Chatter". Within two minutes beta shows the new name.
14. **Beta:** Click **Hide** on one of alpha's posts. It disappears on beta for normal users but stays visible on alpha.
15. **Beta:** Click **Block** on a user from alpha. All of their posts, past and future, are hidden on beta only.

### Keys and failures

16. **Alpha:** Under Admin › Sharing › **Sites that clone your sections**, click **Revoke** for beta's connection. New posts on alpha stop reaching beta, and replies from beta are refused.
17. Optional: stop beta with `docker compose stop beta beta-worker`, post a few times on alpha, then start it again with `docker compose start beta beta-worker`. Beta catches up within two minutes.
18. Optional: under Admin › Settings, switch alpha to **Public** sharing. Beta can then clone a section by entering just `https://alpha.example.com` under **Or browse a public site**, with no code.

### Updates

19. **Alpha:** Under Admin › Updates, you can upload a release package (`alliancebb-X.Y.Z.zip` or `.tar.gz`), or update from GitHub once a release exists. A backup of the code and database is taken first, and **Roll back** puts it back.

## Day-to-day commands

| To | Run |
| --- | --- |
| See logs | `docker compose logs -f alpha alpha-worker` |
| Restart everything | `docker compose restart` |
| Get the latest code from the branch | `git pull && docker compose up -d --build`, then use Admin › Updates or `docker compose exec alpha cp -a /usr/src/alliancebb/. /app/` on each site (see the note below) |
| Open a database shell | `docker compose exec db psql -U alliancebb alliancebb_alpha` |
| Start over completely | `docker compose down -v` (deletes both sites and all their data) |

**Note on code updates:** each site keeps its files in a Docker volume, so that Admin › Updates and plugin uploads survive restarts. Rebuilding the image does not overwrite those files by itself. Either install the new version through Admin › Updates, or copy the new code in with the command above and restart that site's containers.

## If something goes wrong

- **No HTTPS or "connection refused":** check that both DNS records point at the VM and that ports 80 and 443 are open in any firewall. `docker compose logs proxy` shows certificate errors.
- **Cloning fails with a network error:** the sites reach each other through their public addresses. From the VM, `curl -I https://beta.example.com` should work. Some cloud providers block a machine from reaching its own public IP. If yours does, tell me and I'll switch the kit to route between the containers directly.
- **Posts don't arrive:** `docker compose logs alpha-worker beta-worker` shows webhook and sync errors. The worker retries failed deliveries with a growing delay, and also checks every two minutes.
