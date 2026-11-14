output "public_ip" {
  description = "The instance's fixed public IP. Point your domain's A record here if you set one."
  value       = aws_eip.app.public_ip
}

output "public_url" {
  description = "Where the API answers once the first release is deployed."
  value       = var.domain != "" ? "https://${var.domain}" : "http://${aws_eip.app.public_ip}"
}

output "instance_id" {
  description = "For Session Manager: aws ssm start-session --target <this>"
  value       = aws_instance.app.id
}

output "ecr_repository_url" {
  value = aws_ecr_repository.app.repository_url
}

output "data_bucket" {
  description = "Deploy files and nightly database dumps."
  value       = aws_s3_bucket.data.bucket
}

output "github_push_role_arn" {
  value = aws_iam_role.github_push.arn
}

output "github_deploy_role_arn" {
  value = aws_iam_role.github_deploy.arn
}

# Paste these into a terminal to wire the release workflow to this infrastructure (none of them is secret)
output "github_variables" {
  description = "Commands that set the repository variables deploy.yml reads."
  value       = <<-EOT
    gh variable set AWS_REGION --repo ${var.github_repo} --body '${var.region}'
    gh variable set AWS_PUSH_ROLE_ARN --repo ${var.github_repo} --body '${aws_iam_role.github_push.arn}'
    gh variable set AWS_DEPLOY_ROLE_ARN --repo ${var.github_repo} --body '${aws_iam_role.github_deploy.arn}'
    gh variable set ECR_REPOSITORY_URL --repo ${var.github_repo} --body '${aws_ecr_repository.app.repository_url}'
    gh variable set DEPLOY_BUCKET --repo ${var.github_repo} --body '${aws_s3_bucket.data.bucket}'
    gh variable set INSTANCE_ID --repo ${var.github_repo} --body '${aws_instance.app.id}'
    gh variable set PUBLIC_URL --repo ${var.github_repo} --body '${var.domain != "" ? "https://${var.domain}" : "http://${aws_eip.app.public_ip}"}'
  EOT
}

output "admin_key_command" {
  description = "How to read the API's admin key (it is generated, never in Git)."
  value       = "aws ssm get-parameter --region ${var.region} --name /${local.name}/admin_api_key --with-decryption --query Parameter.Value --output text"
}
