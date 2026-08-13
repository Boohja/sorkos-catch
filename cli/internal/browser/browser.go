package browser

import (
	"fmt"
	"os/exec"
	"runtime"
)

func Open(url string) error {
	var command *exec.Cmd
	switch runtime.GOOS {
	case "windows":
		command = exec.Command("rundll32", "url.dll,FileProtocolHandler", url)
	case "linux":
		command = exec.Command("xdg-open", url)
	default:
		return fmt.Errorf("unsupported platform %s", runtime.GOOS)
	}
	return command.Start()
}
