# Deploying to AWS

Tagging a release deploys it to one small EC2 instance, with the infrastructure created by Terraform and the deploy driven by
the GitHub release workflow. There are no long-lived AWS keys in GitHub, no SSH, and no secrets in Git.

## What gets built

```
 git tag v0.1.0
       |
       v
 GitHub Actions (deploy.yml)
   1. build image ---------------> GHCR  and  ECR
   2. smoke test that exact image (digest) against a MySQL service
   3. approval on the "production" environment
   4. deploy -- OIDC: short-lived AWS credentials, nothing stored
       |-- S3 (deploy/) <-- compose file, Caddyfile, schema, deploy.sh
       '-- SSM Run Command --> EC2 instance runs deploy.sh
                                |
              Internet --> Caddy :80/:443 --> app (Apache + PHP) --> MySQL (volume on the EBS disk)
                                |
                                '-- nightly mysqldump --> S3 (dumps/, expire after 30 days)
   5. curl /health on the public URL, then the .NET smoke test
```

| Piece | What it is | Defined in |
|---|---|---|
| Budget | monthly cost alert by email | `infra/budget.tf` |
| ECR | private image repository, keeps the newest 15 images | `infra/ecr.tf` |
| EC2 | one `t3.small`, Amazon Linux 2023, encrypted EBS disk, fixed public IP | `infra/ec2.tf` |
| Network | security group: ports 80 and 443 in, no SSH | `infra/network.tf` |
| S3 | one private bucket: `deploy/` files and `dumps/` backups | `infra/s3.tf` |
| Secrets | database passwords and the admin API key, generated, in SSM Parameter Store | `infra/secrets.tf` |
| IAM | instance role, and two GitHub roles (push, deploy) trusted through OIDC | `infra/iam.tf`, `infra/github_oidc.tf` |
| Stack on the instance | Caddy, the app, MySQL | `deploy/docker-compose.prod.yml` |
| Deploy and rollback | the script SSM runs on the instance | `deploy/deploy.sh` |

The two GitHub roles are each trusted for one thing only. The **push** role can be assumed only by a run for a `v*` tag, and can
only push to this ECR repository. The **deploy** role can be assumed only by a job in the `production` environment, and can only
upload to `deploy/` in the bucket, run a command on this one instance, and read the admin key.

## One-time setup

You need an AWS account and a way for Terraform to sign in as something other than the root user (an IAM Identity Center
profile or an IAM user with administrator rights), plus the `terraform` and `gh` command line tools.

1. **Configure Terraform.**

   ```bash
   cd infra
   cp terraform.tfvars.example terraform.tfvars   # git-ignored; set budget_email at least
   ```

   Set `region`, `domain` and `instance_type` there if you want something other than the defaults
   (`us-east-1`, no domain, `t3.small`). If the account already has a GitHub OIDC provider, set
   `create_github_oidc_provider = false`. The default VPC must exist (it does unless it was deleted).

2. **Review and create the infrastructure.**

   ```bash
   terraform init
   terraform plan -out=tfplan      # read it: this is everything that will be created
   terraform apply tfplan
   ```

   The budget is created first. Wait about five minutes after `apply`: the instance installs Docker on first boot.
   (`deploy.sh` tells you if you are too early.)

3. **Point the workflow at it.** Terraform prints the exact commands (none of the values is secret):

   ```bash
   terraform output -raw github_variables | bash
   ```

4. **Protect production.** In GitHub, Settings > Environments > New environment > `production`, then add yourself under
   *Required reviewers*. The deploy role trusts only that environment name, so a deploy cannot happen without it.

5. **Optional, a domain with HTTPS.** Set `domain = "api.example.com"` in `terraform.tfvars`, run `terraform apply`, and create an A
   record for the name that points at the `public_ip` output. The next deploy picks up the new address and Caddy gets its own
   Let's Encrypt certificate. Without a domain the API answers over plain HTTP on the IP.

6. **Release.**

   ```bash
   git tag v0.1.0 && git push origin v0.1.0
   ```

   Approve the `deploy` job when it waits. The URL is in the `public_url` output and on the workflow run.

Manual steps after this setup: none per release except the approval click.

## How a deploy works

`deploy` runs `deploy/deploy.sh` on the instance, through SSM, with the digest of the image that passed the smoke test:

1. fetches the compose file, Caddyfile and schema from S3, and the secrets from Parameter Store into a root-only `.env`
2. logs in to ECR and pulls the image (a bad tag fails here and nothing changes)
3. restarts the stack on it and waits up to 60 seconds for `/health` to answer with the database up
4. if it does, remembers the files and image as "last known good". If it does not, puts the last known good image and files back,
   and exits with an error, which turns the release job red

Then the workflow calls `/health` on the public URL and runs the .NET `smoke` command against it. The production database starts
empty, so `smoke --provision yes` first creates a reseller and a small product (named "Smoke test ...", one pair per deploy) to place one order.

Secrets are never in Git. Terraform generates them, they sit encrypted in Parameter Store, and the instance reads them at deploy time.

## Rollback

- **Automatic:** a new version that does not become healthy is rolled back by `deploy.sh` itself (image and files), and the job fails.
- **Redeploy an earlier release:** in the Actions tab open the workflow run of the older tag (for example `v0.0.9`) and choose
  *Re-run all jobs*. It rebuilds that commit, smoke-tests it, and deploys it again after approval, using that release's own workflow and deploy files.
- **Database:** there are no migrations to undo. If data was damaged, restore a dump (below).

To rehearse a failed deploy on purpose, tag a release whose `deploy/docker-compose.prod.yml` is broken for the server only, for example
`DB_NAME: nope` in the `app` service. It passes the smoke test (which does not use that file), fails the health check on the instance,
rolls back, and fails the job. (This exact case was exercised locally against a real Docker stack.)

## Operating it

```bash
aws ssm start-session --target <instance_id>          # a shell, no SSH (needs the Session Manager plugin for the AWS CLI)
cd /opt/pluginxchange && sudo docker compose ps       # inside the session
sudo docker compose logs --tail 100 app
sudo systemctl start pxc-backup                       # a backup right now
```

Backups run every night at about 03:15 UTC to `s3://<bucket>/dumps/`, and files older than 30 days are deleted. To restore:

```bash
aws s3 cp s3://<bucket>/dumps/pluginxchange-2026-01-01-0315.sql.gz - | gunzip \
  | sudo docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" pluginxchange'
```

The admin key (to create resellers and import catalogs): `terraform output -raw admin_key_command | bash`.

## Cost

Approximate monthly cost in `us-east-1` at on-demand prices. Check the AWS pricing pages for your region.

| | `t3.small` (default) | `t3.micro` |
|---|---|---|
| EC2 instance | about $15 | about $8 |
| 20 GB gp3 disk | about $2 | about $2 |
| Public IPv4 address | about $4 | about $4 |
| ECR, S3, SSM, data transfer | under $1 for a demo | under $1 |
| **Total** | **about $20** | **about $13** |

`t3.micro` has 1 GB of memory, which is tight for MySQL 8. The budget alert is set for the whole account (default $25 a month).

## Tearing it down

`terraform destroy` removes everything, including the database disk and the bucket with its backups. Copy any dump you want to keep
out of the bucket first. The Terraform state file (`infra/terraform.tfstate`) is the only record of what exists, so keep it.

## Security notes

- **Terraform state holds the generated passwords.** It is git-ignored and kept locally. A shared or remote state would need an
  encrypted S3 backend with locking.
- **Accepted scan findings** (marked in the code with `trivy:ignore` and a reason): mutable ECR tags (a re-run of a release pushes the same
  tag), unrestricted outbound traffic (the instance reaches ECR, S3, SSM, Docker Hub, GitHub and Let's Encrypt, none of which has a fixed
  address range), and AWS-managed instead of customer-managed encryption keys, no S3 access logging and no S3 versioning (cost for a demo).
- **The database is on one disk of one instance.** There is no failover. The nightly dump to S3 is the recovery path, which is why
  restoring one is documented above. Moving to RDS and several instances is what Phase 10 is about.
- **One instance means a short outage on every deploy** while the app container restarts.
