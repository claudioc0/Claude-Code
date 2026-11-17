# Arquitetura Técnica

Este documento descreve os componentes centrais planejados para o clone experimental do Claude Code.

---

## 1. Core: Loop Agêntico (`src/core/`)

O núcleo do sistema opera em um ciclo **ReAct / Tool-Calling Loop**:

1. **Montagem de Contexto:** Combina `system prompt`, instruções do repositório (`CLAUDE.md`), histórico da conversa e schemas das ferramentas habilitadas.
2. **Chamada ao Provider LLM:** Envia a requisição com suporte a *streaming* de texto e emissão de `tool_calls`.
3. **Despacho de Ferramentas:**
   - Se o modelo retornar apenas texto, a resposta é exibida ao usuário e o turno termina.
   - Se o modelo solicitar uma ou mais `tool_calls`, cada chamada passa pela camada de permissões antes da execução.
4. **Retorno de Resultados:** O resultado (ou erro/recusa) é formatado, truncado se necessário e anexado ao histórico como mensagem de ferramenta, iniciando uma nova iteração até a conclusão da tarefa.

---

## 2. Camada de Ferramentas (`src/tools/`)

As ferramentas são projetadas para serem atômicas, previsíveis e econômicas em tokens:

| Ferramenta | Fase | Responsabilidade |
| :--- | :---: | :--- |
| `read_file` | 1 | Leitura paginada por intervalo de linhas (`start_line`, `end_line`) com numeração. |
| `write_file` | 1 | Criação ou sobrescrita completa de arquivos. |
| `run_command` | 1 | Execução de comandos no shell local com timeout e captura de `stdout`/`stderr`. |
| `glob` | 2 | Listagem de arquivos no workspace respeitando `.gitignore`. |
| `grep_search` | 2 | Busca textual/regex no codebase (priorizando `ripgrep` quando disponível). |
| `edit_file` | 2 | Substituição cirúrgica (`old_string` → `new_string`) validando ocorrência única. |
| `delegate_task` | 5 | Subagente read-only em contexto isolado para explorações extensas. |

---

## 3. Permissões e Segurança (`src/permissions/`)

Para evitar ações destrutivas acidentais, toda ferramenta mutável ou de execução passa por um *gate* de permissão:

- **Modos de Operação:**
  - `ask` *(padrão)*: exige confirmação explícita do usuário para edições de arquivo e comandos shell.
  - `auto-edit`: aprova leituras e edições de arquivo automaticamente; confirma apenas comandos shell.
  - `plan`: modo somente leitura; bloqueia escrita e execução de comandos.
- **Guardrails:**
  - Validação estrita de caminhos para impedir *path traversal* fora da raiz do workspace.
  - Listas configuráveis de comandos permitidos (*allowlist*) e bloqueados (*denylist*).

---

## 4. Gerenciamento de Contexto (`src/core/context`)

Sessões longas em codebases reais consomem a janela de contexto rapidamente. Estratégias previstas:

- **Memória Persistente:** Injeção automática de `CLAUDE.md` no `system prompt`.
- **Truncamento de Outputs:** Saídas extensas de shell ou buscas são truncadas com indicação de linhas omitidas.
- **Compactação (`/compact`):** Sumarização do histórico antigo mantendo decisões, arquivos modificados e estado atual.

---

## 5. Interface e Experiência de Terminal (`src/ui/`)

- **Renderização:** Streaming de tokens em tempo real, blocos de código formatados e diffs coloridos (`-` / `+`) antes da confirmação de edições.
- **Slash Commands:** `/help`, `/clear`, `/compact`, `/model`, `/mode`, `/cost`, `/undo`.
- **Modo Headless:** Execução não-interativa via flag (ex: `-p "prompt"`) e suporte a entrada via pipe (`stdin`).
