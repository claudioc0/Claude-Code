# Roadmap de Desenvolvimento

Plano incremental em 5 fases para construir o agente do zero até recursos avançados.

---

## Fase 1: MVP — Loop Agêntico Mínimo (REPL)
**Objetivo:** Agente funcional no terminal capaz de ler arquivos, editar código e executar comandos.

- [ ] Setup inicial do projeto, gerenciamento de dependências e configuração de `.env`.
- [ ] Abstração mínima de provider LLM com suporte a *streaming* e *tool calling*.
- [ ] Loop agêntico principal (envio de mensagens, execução de `tool_calls`, retorno de resultados).
- [ ] Ferramentas iniciais:
  - [ ] `read_file`
  - [ ] `write_file`
  - [ ] `run_command`
- [ ] Prompt simples de confirmação `[s/N]` antes de ações mutáveis.

---

## Fase 2: Exploração de Codebase e Edição Inteligente
**Objetivo:** Navegar projetos reais com precisão e baixo consumo de tokens.

- [ ] Ferramenta `glob` respeitando regras de `.gitignore`.
- [ ] Ferramenta `grep_search` para busca por texto/regex.
- [ ] Ferramenta `edit_file` (search-and-replace com validação de unicidade).
- [ ] Preview de diff colorido no terminal antes da aprovação de edições.

---

## Fase 3: Permissões, Segurança e Contexto
**Objetivo:** Controle fino de autonomia, proteção do workspace e gestão da janela de contexto.

- [ ] Modos de permissão: `ask`, `auto-edit` e `plan`.
- [ ] Proteção contra *path traversal* fora do workspace.
- [ ] Allowlist/denylist configurável para comandos shell.
- [ ] Carregamento automático de `CLAUDE.md` no `system prompt`.
- [ ] Truncamento inteligente de outputs longos.
- [ ] Contagem estimada de tokens e comando `/compact`.

---

## Fase 4: Experiência de Terminal (TUI) e Slash Commands
**Objetivo:** Interface de terminal polida e produtiva.

- [ ] Renderização de Markdown e syntax highlighting no terminal.
- [ ] Spinners e indicadores de progresso para chamadas de API e ferramentas.
- [ ] Barra de status com modelo ativo, modo de permissão e uso de tokens/custo.
- [ ] Slash commands: `/help`, `/clear`, `/compact`, `/model`, `/mode`, `/cost`.
- [ ] Modo não-interativo / pipe (`-p "..."`).

---

## Fase 5: Recursos Avançados (Experimentos)
**Objetivo:** Explorar capacidades avançadas de agentes de código.

- [ ] Sistema de checkpoints / `/undo` para reverter alterações da sessão.
- [ ] Subagentes isolados (`delegate_task`) para pesquisas sem poluir o contexto principal.
- [ ] Cliente MCP (Model Context Protocol) via `stdio` para ferramentas externas.
