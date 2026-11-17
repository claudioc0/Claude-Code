# CLAUDE.md

Este arquivo define o contexto e as convenções deste repositório para assistentes de código (e futuramente para o próprio agente desenvolvido neste projeto).

## Visão Geral
- **Projeto:** Clone experimental do Claude Code.
- **Idioma da documentação:** Português (PT-BR).
- **Código e identificadores:** Inglês, seguindo convenções limpas e modulares.

## Princípios de Desenvolvimento
1. **Incrementalidade:** Construir e validar uma fase por vez antes de adicionar complexidade.
2. **Segurança por padrão:** Toda ferramenta que altera arquivos ou executa comandos deve passar pela camada de permissões e respeitar os limites do workspace.
3. **Economia de contexto:** Ferramentas de leitura e busca devem suportar paginação/limites para evitar sobrecarga na janela de contexto do LLM.
4. **Testabilidade:** Manter o núcleo agêntico desacoplado da interface de terminal (TUI/REPL) e dos providers específicos de LLM.
