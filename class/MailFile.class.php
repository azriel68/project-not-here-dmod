<?php

namespace Dolibarr\Cowork;

class MailFile {

    public string $path;

    public string $name;

    public string $mimetype;

    public function __construct(string $path)
    {
        $this->path = $path;

        $this->name = basename($path);

        $this->mimetype = mime_content_type($path);

        if(false === $this->mimetype) {
            throw new \Exception("Attach mail file, invalid path ".$path);
        }

    }

}
