# Laravel Asynq – User Guide

Run your Laravel jobs at scale with [Asynq](https://github.com/hibiken/asynq), a fast Redis-based queue engine written in Go.
This package lets you **dispatch jobs from Laravel (PHP)** and process them with **Go workers**, unlocking massive concurrency and performance while keeping Laravel as your main framework.

---

## 📐 Architecture

```text
+-------------------+          +-------------------+          +-------------------+
|                   |  enqueue |                   |  pull    |                   |
|   Laravel (PHP)   +--------->+       Redis       +--------->+   Go Worker       |
|   Producer        |          |   Task Queue      |          |   Consumer        |
| (laravel-asynq)   |          |   (asynq keys)    |          | (hibiken/asynq)   |
+-------------------+          +-------------------+          +-------------------+
        |                                                              |
        |                                                              v
        |                                                +-----------------------+
        |                                                |  Your business logic  |
        |                                                | (send email, save     |
        |                                                |  story, call APIs…)   |
        |                                                +-----------------------+
        |
        +--> Developer-friendly Laravel API:
             Asynq::enqueue('story.save', [...])
```

---

## ✍️ Example 1 – Dispatch a simple story

### Laravel (producer)

```php
use AnasEqal\LaravelAsynq\Facades\Asynq;

Asynq::enqueueTask(
    'story.save',                   // task type
    [
        'title' => 'My First Story',
        'body'  => 'Once upon a time…',
    ],
    'stories', # queue name
    [
        'delay' => 5,
        'retry' => 5,
        'timeout' => 1800
    ]
);
```

### Go (consumer)

**go.mod**

```bash
go mod init consumer
go get github.com/hibiken/asynq
```

**main.go**

```gopackage main
package main

import (
	"context"
	"encoding/json"
	"fmt"
	"log"
	"os"
	"path/filepath"

	"github.com/hibiken/asynq"
)

const TaskStorySave = "story.save"

type StoryPayload struct {
	Title string `json:"title"`
	Body  string `json:"body"`
}

func main() {
	srv := asynq.NewServer(
		asynq.RedisClientOpt{Addr: getenv("ASYNQ_REDIS_ADDR", "127.0.0.1:6379"), DB: atoi(getenv("ASYNQ_REDIS_DB", "5"))},
		asynq.Config{Concurrency: 8, Queues: map[string]int{"stories": 1}},
	)

	mux := asynq.NewServeMux()
	mux.HandleFunc(TaskStorySave, handleStorySave)

	log.Println("Story worker running…")
	if err := srv.Run(mux); err != nil {
		log.Fatal(err)
	}
}

func handleStorySave(ctx context.Context, t *asynq.Task) error {
	var p StoryPayload
	if err := json.Unmarshal(t.Payload(), &p); err != nil {
		return fmt.Errorf("invalid payload: %w", err)
	}
	if p.Title == "" || p.Body == "" {
		return fmt.Errorf("title and body required")
	}

	dir := getenv("STORIES_DIR", "stories")
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return err
	}

	file := filepath.Join(dir, fmt.Sprintf("%s.txt", t.Type()))
	content := fmt.Sprintf("%s\n\n---\n\n%s\n", p.Title, p.Body)
	if err := os.WriteFile(file, []byte(content), 0o644); err != nil {
		return err
	}

	log.Printf("Saved story to %s", file)
	return nil
}

// helpers
func getenv(k, d string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return d
}
func atoi(s string) int { var n int; fmt.Sscanf(s, "%d", &n); return n }
```

Run it:

```bash
go mod tidy
go run .
```

✅ Each dispatched job creates a text file in `stories/` with the title and body.

---

## ✍️ Example 2 – Dispatch an HTTP Request

### Laravel (producer)

```php
use AnasEqal\LaravelAsynq\Facades\Asynq;

Asynq::enqueueTask(
    'http.request',                 // task type
    [
        'url'     => 'https://jsonplaceholder.typicode.com/posts',
        'method'  => 'POST',
        'headers' => [
            'Content-Type' => 'application/json'
        ],
        'body'    => json_encode([
            'title' => 'foo',
            'body'  => 'bar',
            'userId'=> 1
        ]),
    ],
    'http-jobs', # queue name
    [
        'retry' => 3,
        'timeout' => 30
    ]
);
```

---

### Go (consumer)

**go.mod**

```bash
go mod init httpworker
go get github.com/hibiken/asynq
```

**main.go**

```go
package main

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"strings"

	"github.com/hibiken/asynq"
)

const TaskHTTPRequest = "http.request"

type HTTPPayload struct {
	URL     string            `json:"url"`
	Method  string            `json:"method"`
	Headers map[string]string `json:"headers"`
	Body    string            `json:"body"`
}

func main() {
	srv := asynq.NewServer(
		asynq.RedisClientOpt{Addr: getenv("ASYNQ_REDIS_ADDR", "127.0.0.1:6379"), DB: atoi(getenv("ASYNQ_REDIS_DB", "6"))},
		asynq.Config{Concurrency: 8, Queues: map[string]int{"http-jobs": 1}},
	)

	mux := asynq.NewServeMux()
	mux.HandleFunc(TaskHTTPRequest, handleHTTPRequest)

	log.Println("HTTP worker running…")
	if err := srv.Run(mux); err != nil {
		log.Fatal(err)
	}
}

func handleHTTPRequest(ctx context.Context, t *asynq.Task) error {
	var p HTTPPayload
	if err := json.Unmarshal(t.Payload(), &p); err != nil {
		return fmt.Errorf("invalid payload: %w", err)
	}

	req, err := http.NewRequestWithContext(ctx, strings.ToUpper(p.Method), p.URL, strings.NewReader(p.Body))
	if err != nil {
		return fmt.Errorf("build request: %w", err)
	}

	for k, v := range p.Headers {
		req.Header.Set(k, v)
	}

	client := &http.Client{}
	resp, err := client.Do(req)
	if err != nil {
		return fmt.Errorf("http error: %w", err)
	}
	defer resp.Body.Close()

	bodyBytes, _ := io.ReadAll(resp.Body)
	log.Printf("HTTP %s %s -> %d, resp: %s", p.Method, p.URL, resp.StatusCode, string(bodyBytes))

	if resp.StatusCode >= 400 {
		return fmt.Errorf("remote returned %d", resp.StatusCode)
	}

	return nil
}

// helpers
func getenv(k, d string) string {
	if v := getenvOS(k); v != "" {
		return v
	}
	return d
}
func getenvOS(k string) string { return "" + (map[string]string{}[k]) } // stub if no os import
func atoi(s string) int { var n int; fmt.Sscanf(s, "%d", &n); return n }
```

---

### Run the worker

```bash
go mod tidy
go run .
```

---

✅ Now when you call the Laravel route, the Go worker will send a real HTTP POST request to the target API.
You’ll see logs like:

```text
HTTP POST https://jsonplaceholder.typicode.com/posts -> 201, resp: {"id":101,...}
```

---

## 🔍 Monitoring

* Asynq ships with a [Web UI](https://github.com/hibiken/asynqmon) for monitoring tasks, retries, failures.
* Run it:

```bash
go install github.com/hibiken/asynqmon/cmd/asynqmon@latest
asynqmon --redis-addr=127.0.0.1:6379
```

---

## 🛠 Troubleshooting

* **No job processed?**

  * Check Redis is running.
  * Verify `ASYNQ_REDIS_ADDR/DB` match in Laravel and Go.
  * Ensure task type string is identical.

* **Permission denied writing files?**

  * Make sure Go worker has rights to create/write in `stories/`.

* **Job keeps retrying?**

  * Check Go handler returns `error` only when failure is real.
  * If success, return `nil`.

---

## ✅ Summary

* **Laravel** is the producer: enqueue jobs with `Asynq::enqueueTask`.
* **Go** is the consumer: process jobs with blazing concurrency.
* **Redis** is the broker: shared task queue.
* Together, they give you a **Laravel-friendly + Go-powered** queue that scales to tens of thousands of jobs per second.

👉 The `story.save` example is the easiest way to confirm everything works.
From there, you can build any job type you want: emails, webhooks, image processing, etc.
