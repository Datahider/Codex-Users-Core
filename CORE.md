# Core State

`Core` хранит только свои собственные runtime-данные:

- session routing внутри core
- active task state
- scheduler state
- watcher state
- queue state

Во внутренних state-файлах не должно быть source-specific UI и delivery-specific metadata.
