[0;1;32m●[0m sshd.service - OpenSSH server daemon
     Loaded: loaded (]8;;file://VUTRUC/usr/lib/systemd/system/sshd.service/usr/lib/systemd/system/sshd.service]8;;; [0;1;32menabled[0m; preset: [0;1;32menabled[0m)
     Active: [0;1;32mactive (running)[0m since Wed 2026-10-14 11:49:03 +07; 6s ago
       Docs: ]8;;man:sshd(8)man:sshd(8)]8;;
             ]8;;man:sshd_config(5)man:sshd_config(5)]8;;
   Main PID: 3382 (sshd)
      Tasks: 1 (limit: 49676)
     Memory: 1.3M (peak: 1.4M)
        CPU: 14ms
     CGroup: /system.slice/sshd.service
             └─[0;38;5;245m3382 "sshd: /usr/sbin/sshd -D [listener] 0 of 10-100 startups"[0m

Oct 14 11:49:03 VUTRUC systemd[1]: Starting OpenSSH server daemon...
Oct 14 11:49:03 VUTRUC sshd[3382]: Server listening on 0.0.0.0 port 22.
Oct 14 11:49:03 VUTRUC sshd[3382]: Server listening on :: port 22.
Oct 14 11:49:03 VUTRUC systemd[1]: Started OpenSSH server daemon.
