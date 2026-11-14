# Generated secrets live in SSM Parameter Store (encrypted) and are read by deploy.sh on the instance.
# They are also in the Terraform state file, which therefore must stay private (it is git-ignored).
resource "random_password" "db_password" {
  length  = 32
  special = false
}

resource "random_password" "db_root_password" {
  length  = 32
  special = false
}

resource "random_password" "admin_api_key" {
  length  = 48
  special = false
}

resource "aws_ssm_parameter" "db_password" {
  name  = "/${local.name}/db_password"
  type  = "SecureString"
  value = random_password.db_password.result
}

resource "aws_ssm_parameter" "db_root_password" {
  name  = "/${local.name}/db_root_password"
  type  = "SecureString"
  value = random_password.db_root_password.result
}

resource "aws_ssm_parameter" "admin_api_key" {
  name  = "/${local.name}/admin_api_key"
  type  = "SecureString"
  value = random_password.admin_api_key.result
}

resource "aws_ssm_parameter" "site_address" {
  name  = "/${local.name}/site_address"
  type  = "String"
  value = local.site_address
}
