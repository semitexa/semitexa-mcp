# Semitexa MCP

A Model Context Protocol server through which a coding agent (Claude Code, Cursor, Claude Desktop) learns a Semitexa project, generates code the framework expects, and checks what it changed.

## Install

Not yet part of the installer. Until its first release it is available as `dev-master`:

```bash
composer require --dev semitexa/mcp:dev-master
```

## Purpose

An agent that guesses a framework's conventions writes code that looks right and is not. This package gives the agent the framework's own tools instead: what the project contains, how a page or a module is made here, and the same checks a developer runs before calling a change done.

## Connect an agent

The server speaks MCP over stdio and runs only when `APP_ENV=dev`, because its tools change the project. `bin/semitexa mcp` starts it in the app container; `bin/semitexa mcp:config` prints what to give each agent, with this project's path:

```bash
claude mcp add semitexa -- "/path/to/project/bin/semitexa" mcp
```

```json
{"mcpServers": {"semitexa": {"command": "/path/to/project/bin/semitexa", "args": ["mcp"]}}}
```

The second form is for Cursor (`.cursor/mcp.json`) and Claude Desktop (`claude_desktop_config.json`).

Protocol versions `2025-06-18`, `2025-03-26` and `2024-11-05` are answered; a client asking for another gets the newest.

## Role in Semitexa

Depends on `semitexa/core`. The tools, resources and prompts come from providers in the installed packages, so a package adds its own without this one knowing it.

## Key Features

- `#[AsMcpProvider]` to declare a provider, built through the container
- `McpToolProviderInterface`, `McpResourceProviderInterface`, `McpPromptProviderInterface`
- Read-only, destructive and idempotent hints on every tool, so a client can ask the user first
- A tool's failure reaches the agent as a result it can read, not as a protocol error
- A provider that cannot be built is left out and named on stderr; the others keep working
- stdout carries protocol messages only, even when a tool prints

## Tools

- Know: `semitexa_orient`, `semitexa_ask`, `semitexa_task`, `semitexa_routes`, `semitexa_route`, `semitexa_logs`
- Do: `semitexa_scaffold` (a recipe), `semitexa_generate` (any `make:*` generator), `semitexa_invoke`
- Check: `semitexa_verify`, `semitexa_doctor`

Generators show what they would create unless asked to write, and never overwrite. Each recipe is also a prompt, and the project's `AGENTS.md` and the framework's docs pages are resources.

## Docs

https://semitexa.com/docs/reference/commands-mcp
