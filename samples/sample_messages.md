
```json
{"jsonrpc": "2.0","id": 1,"method": "initialize","params": {"protocolVersion": "2024-11-05","capabilities": {"roots": {"listChanged": true},"sampling": {}},"clientInfo": {"name": "ExampleClient","version": "1.0.0"}}}
```

```json
{"jsonrpc": "2.0", "method": "notifications/initialized"}
```

```json
{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name": "greet_user","arguments":{"name":"Kyrian"}}}
{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name": "get_last_sum","arguments":{}}}
```

```json
{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name": "anotherTool", "arguments": {"input": "test data"}}}
```

```json
{"jsonrpc":"2.0","id":4,"method":"tools/list"}
```

```json
{"jsonrpc":"2.0","id":5,"method":"resources/list"}
```

```json
{"jsonrpc":"2.0","id":6,"method":"resources/read","params":{"uri": "config://app/name"}}
```

```json
{"jsonrpc":"2.0","id":7,"method":"resources/read","params":{"uri": "file://data/users.csv"}}
```

```json
{"jsonrpc":"2.0","id":8,"method":"resources/templates/list"}
```

```json
{"jsonrpc":"2.0","id":9,"method":"prompts/list"}
```

```json
{"jsonrpc":"2.0","id":10,"method":"prompts/get","params":{"name": "create_story", "arguments": {"subject": "a lost robot", "genre": "sci-fi"}}}
```

```json
{"jsonrpc":"2.0","id":11,"method":"prompts/get","params":{"name": "simplePrompt"}}
```
