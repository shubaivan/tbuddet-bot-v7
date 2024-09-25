<?php


namespace App\Entity;


use Doctrine\Common\Collections\Collection;

interface AttachmentFilesInterface
{
    public function checkFileExist($name);

    public function getFiles(): Collection;
}