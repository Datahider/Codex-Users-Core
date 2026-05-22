# Layers

## Router

- принимает внешний ingress
- отдаёт `Core` поток событий
- принимает outbound от `Core`

## Core

- читает события из `Router`
- обрабатывает локальные очереди
- управляет Codex session flow

## External Adapters

- находятся вне этого репозитория
- сами решают delivery/UI детали
- не пишут напрямую в state `Core`
