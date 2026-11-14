variable "region" {
  description = "AWS region for everything."
  type        = string
  default     = "us-east-1"
}

variable "budget_email" {
  description = "Email that gets the cost alerts. Required: the budget is the first thing this creates."
  type        = string

  validation {
    condition     = can(regex("^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$", var.budget_email))
    error_message = "budget_email must be an email address."
  }
}

variable "budget_limit_usd" {
  description = "Monthly cost limit in USD for the whole AWS account. Alerts at 80% spent and when 100% is forecast."
  type        = number
  default     = 25
}

variable "github_repo" {
  description = "GitHub repository allowed to deploy, as owner/name (case as on GitHub)."
  type        = string
  default     = "AjayMaan13/PluginXchange"

  validation {
    condition     = can(regex("^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$", var.github_repo))
    error_message = "github_repo must look like owner/name."
  }
}

variable "instance_type" {
  description = "EC2 instance type (x86_64: the release image is built for amd64). t3.micro is cheaper but tight for MySQL."
  type        = string
  default     = "t3.small"
}

variable "disk_gb" {
  description = "Size of the instance's EBS disk, which also holds the database."
  type        = number
  default     = 20
}

variable "domain" {
  description = "Optional domain name pointed at the instance's IP. Set it for automatic HTTPS; leave empty for plain HTTP on the IP."
  type        = string
  default     = ""
}

variable "create_github_oidc_provider" {
  description = "Set to false if this AWS account already has a GitHub OIDC provider (only one per account is allowed)."
  type        = bool
  default     = true
}

variable "compose_version" {
  description = "Docker Compose release installed on the instance on first boot."
  type        = string
  default     = "v5.7.1"
}
