{
  description = "Module parc Linux MDM (logiciels + sync)";

  outputs = { self, ... }: {
    nixosModules.default = import ./parc.nix;
    nixosModules.parc = import ./parc.nix;
    nixosModules.autoUpgrade = import ./parc.nix;
  };
}
